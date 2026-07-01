<?php

namespace WikiOasis\WikiOasisMagic\JobQueue;

use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\JobQueueRedis;
use MediaWiki\JobQueue\RunnableJob;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionSource;
use Throwable;
use function Sentry\continueTrace;
use function Sentry\startTransaction;

/**
 * A Redis-backed job queue that instruments enqueue and processing of jobs for
 * Sentry, following the Sentry "Queues" module conventions.
 *
 * @see https://docs.sentry.io/platforms/php/tracing/instrumentation/queues-module/
 *
 * This behaves exactly like {@see JobQueueRedis} (and takes the same configuration),
 * but additionally:
 *
 *  - Producer side: wraps {@see doBatchPush()} in a `queue.publish` span and
 *    propagates the active trace into each job by storing the `sentry-trace` and
 *    `baggage` headers alongside the job blob.
 *  - Consumer side: when a job is popped ({@see doPop()}) it continues the trace
 *    captured at publish time and starts a `queue.process` transaction that records
 *    the message id, destination, receive latency and retry count. The transaction
 *    is finished when the job is acknowledged ({@see doAck()}), with its status set
 *    from the job's recorded error so successes and failures are reported distinctly.
 *
 * The job runner pops, runs and (on success, or when retries are exhausted)
 * acknowledges a single job at a time within one process. We rely on that ordering:
 * a job whose `queue.process` transaction is still open when the next job is popped,
 * or when the process shuts down, was never acknowledged - i.e. a retryable failure
 * or a crash - and is reported as an error.
 *
 * All Sentry calls are no-ops unless a Sentry client has been initialised, so this
 * class is safe to use on wikis where Sentry is not configured.
 *
 * Configure via $wgJobTypeConf, e.g.:
 * @code
 * $wgJobTypeConf['default'] = [
 *     'class'        => WikiOasis\WikiOasisMagic\JobQueue\JobQueueSentryRedis::class,
 *     'redisServer'  => '127.0.0.1:6379',
 *     'redisConfig'  => [ 'connectTimeout' => 2 ],
 *     'daemonized'   => true,
 * ];
 * @endcode
 */
class JobQueueSentryRedis extends JobQueueRedis {

	/** Messaging system label reported to Sentry. */
	private const MESSAGING_SYSTEM = 'redis';

	/** @var Transaction|null The in-flight `queue.process` transaction, if any. */
	private $pendingProcessTransaction = null;

	/** @var string|null UUID of the job owning {@see $pendingProcessTransaction}. */
	private $pendingProcessUuid = null;

	/** @var Span|null The span that was active on the hub before we started processing. */
	private $previousSpan = null;

	/** @var bool Whether a shutdown finalizer has been registered for this instance. */
	private $shutdownRegistered = false;

	/**
	 * @return bool Whether a Sentry client is available to receive instrumentation.
	 */
	private function isSentryEnabled(): bool {
		return class_exists( SentrySdk::class )
			&& SentrySdk::getCurrentHub()->getClient() !== null;
	}

	/**
	 * Best-effort serialized size (in bytes) of a job's payload, used for the
	 * `messaging.message.body.size` attribute.
	 *
	 * @param array $params
	 * @return int
	 */
	private function payloadSize( array $params ): int {
		return strlen( serialize( $params ) );
	}

	/**
	 * @inheritDoc
	 *
	 * Captures the active trace into each pushed job and wraps the push in a
	 * `queue.publish` span.
	 */
	protected function doBatchPush( array $jobs, $flags ) {
		if ( !$this->isSentryEnabled() ) {
			parent::doBatchPush( $jobs, $flags );
			return;
		}

		$hub = SentrySdk::getCurrentHub();
		$parentSpan = $hub->getSpan();
		// Without an active transaction there is nothing to attach a publish span to,
		// and no trace to propagate to consumers.
		if ( $parentSpan === null ) {
			parent::doBatchPush( $jobs, $flags );
			return;
		}

		$context = SpanContext::make()
			->setOp( 'queue.publish' )
			->setDescription( 'queue.publish: ' . $this->type )
			->setOrigin( 'auto.queue.wikioasis' );
		$span = $parentSpan->startChild( $context );

		$bodySize = 0;
		foreach ( $jobs as $job ) {
			$bodySize += $this->payloadSize( $job->getParams() );
		}
		$span->setData( [
			'messaging.system' => self::MESSAGING_SYSTEM,
			'messaging.destination.name' => $this->type,
			'messaging.message.body.size' => $bodySize,
			'messaging.batch.message_count' => count( $jobs ),
		] );

		// Make the publish span the active one so getNewJobFields() captures it as
		// the parent of each consumer-side `queue.process` transaction.
		$hub->setSpan( $span );
		try {
			parent::doBatchPush( $jobs, $flags );
			$span->setStatus( SpanStatus::ok() );
		} catch ( Throwable $e ) {
			$span->setStatus( SpanStatus::internalError() );
			throw $e;
		} finally {
			$span->finish();
			$hub->setSpan( $parentSpan );
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Stores the active trace headers alongside the job so the consumer can continue
	 * the same trace when the job is later processed.
	 */
	protected function getNewJobFields( IJobSpecification $job ) {
		$fields = parent::getNewJobFields( $job );

		if ( $this->isSentryEnabled() ) {
			$span = SentrySdk::getCurrentHub()->getSpan();
			if ( $span !== null ) {
				$fields['sentryTrace'] = $span->toTraceparent();
				$fields['sentryBaggage'] = $span->toBaggage();
			}
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 *
	 * Restores the propagated trace headers onto the reconstructed job as metadata.
	 */
	protected function getJobFromFields( array $fields ) {
		$job = parent::getJobFromFields( $fields );

		if ( $job ) {
			$job->setMetadata( 'sentryTrace', $fields['sentryTrace'] ?? '' );
			$job->setMetadata( 'sentryBaggage', $fields['sentryBaggage'] ?? '' );
		}

		return $job;
	}

	/**
	 * @inheritDoc
	 *
	 * Starts a `queue.process` transaction for the popped job, continuing the trace
	 * captured at publish time.
	 */
	protected function doPop() {
		// A still-open transaction here belongs to a previously popped job that was
		// never acknowledged: a retryable failure or a crash. Report it as an error.
		$this->finishPendingProcessSpan( false );

		$job = parent::doPop();

		if ( $job !== false && $this->isSentryEnabled() ) {
			$this->startProcessSpan( $job );
		}

		return $job;
	}

	/**
	 * @inheritDoc
	 *
	 * Finishes the `queue.process` transaction for the acknowledged job. The runner
	 * acknowledges both successful jobs and failed jobs that are out of retries, so
	 * the status is taken from the job's recorded error.
	 */
	protected function doAck( RunnableJob $job ) {
		$res = parent::doAck( $job );

		if ( $this->pendingProcessTransaction !== null
			&& (string)$job->getMetadata( 'uuid' ) === $this->pendingProcessUuid
		) {
			$error = $job->getLastError();
			$ok = ( $error === null || $error === '' );
			$this->finishPendingProcessSpan( $ok );
		}

		return $res;
	}

	/**
	 * Begin a `queue.process` transaction for a job being handed to the runner.
	 *
	 * @param RunnableJob $job
	 */
	private function startProcessSpan( RunnableJob $job ): void {
		$sentryTrace = (string)$job->getMetadata( 'sentryTrace' );
		$baggage = (string)$job->getMetadata( 'sentryBaggage' );

		$context = continueTrace( $sentryTrace, $baggage );
		$context->setName( 'queue.process: ' . $this->type );
		$context->setOp( 'queue.process' );
		$context->setSource( TransactionSource::task() );

		$transaction = startTransaction( $context );

		$enqueuedAt = (int)$job->getMetadata( 'timestamp' );
		$attempts = (int)$job->getMetadata( 'attempts' );

		$transaction->setData( [
			'messaging.system' => self::MESSAGING_SYSTEM,
			'messaging.destination.name' => $this->type,
			'messaging.message.id' => (string)$job->getMetadata( 'uuid' ),
			'messaging.message.body.size' => $this->payloadSize( $job->getParams() ),
			// Sentry expects the receive latency in milliseconds.
			'messaging.message.receive.latency' =>
				$enqueuedAt > 0 ? max( 0, time() - $enqueuedAt ) * 1000 : 0,
			'messaging.message.retry.count' => $attempts > 0 ? $attempts - 1 : 0,
		] );

		$hub = SentrySdk::getCurrentHub();
		$this->previousSpan = $hub->getSpan();
		$hub->setSpan( $transaction );

		$this->pendingProcessTransaction = $transaction;
		$this->pendingProcessUuid = (string)$job->getMetadata( 'uuid' );

		$this->registerShutdownFinalizer();
	}

	/**
	 * Finish the in-flight `queue.process` transaction, if any.
	 *
	 * @param bool $ok Whether the job completed successfully.
	 */
	private function finishPendingProcessSpan( bool $ok ): void {
		if ( $this->pendingProcessTransaction === null ) {
			return;
		}

		$transaction = $this->pendingProcessTransaction;
		$transaction->setStatus( $ok ? SpanStatus::ok() : SpanStatus::internalError() );
		$transaction->finish();

		SentrySdk::getCurrentHub()->setSpan( $this->previousSpan );

		$this->pendingProcessTransaction = null;
		$this->pendingProcessUuid = null;
		$this->previousSpan = null;
	}

	/**
	 * Ensure a job whose process transaction is still open at shutdown (e.g. a fatal
	 * error during run()) is reported as a failure rather than silently dropped.
	 */
	private function registerShutdownFinalizer(): void {
		if ( $this->shutdownRegistered ) {
			return;
		}
		$this->shutdownRegistered = true;

		register_shutdown_function( function () {
			$this->finishPendingProcessSpan( false );
		} );
	}
}
