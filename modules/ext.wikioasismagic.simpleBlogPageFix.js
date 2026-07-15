/**
 * Compatibility shim for SimpleBlogPage + OOJSPlus version mismatch.
 *
 * SimpleBlogPage's BlogList panel calls `this.paginator.init()` after the
 * data store resolves, but older versions of OOJSPlus do not define an
 * `init()` method on the Paginator prototype, causing:
 *
 *   TypeError: this.paginator.init is not a function
 *
 * This shim adds `init()` to the prototype if it is absent, so the call
 * becomes a safe no-op (the paginator is already set up via its constructor
 * in older OOJSPlus versions).
 *
 * Remove this file once OOJSPlus is updated to a version that natively
 * exposes `Paginator.prototype.init`.
 *
 * ext.oOJSPlus.data is loaded via mw.loader.using() rather than a static
 * extension.json dependency: this shim's own module is registered
 * unconditionally, but OOJSPlus is only actually installed alongside
 * SimpleBlogPage on wikis that use it, so a hard dependency would not
 * resolve everywhere this extension is loaded.
 */
( function () {
	'use strict';

	mw.loader.using( 'ext.oOJSPlus.data' ).then( function () {
		// Guard: only patch if OOJSPlus data pagination is present and init() is missing.
		if (
			typeof OOJSPlus === 'undefined' ||
			!OOJSPlus.ui ||
			!OOJSPlus.ui.data ||
			!OOJSPlus.ui.data.pagination ||
			!OOJSPlus.ui.data.pagination.Paginator
		) {
			return;
		}

		if ( typeof OOJSPlus.ui.data.pagination.Paginator.prototype.init === 'function' ) {
			// Already defined in this OOJSPlus version — nothing to do.
			return;
		}

		/**
		 * Initialise the paginator after the first store load.
		 *
		 * In newer OOJSPlus versions this method is called explicitly by the
		 * consuming panel (e.g. BlogList) once the store has resolved its first
		 * request. In older versions the equivalent setup happens inside the
		 * constructor, so this shim is a safe no-op that prevents the TypeError.
		 */
		OOJSPlus.ui.data.pagination.Paginator.prototype.init = function () {
			if ( this._wikioasisPaginatorInitialized ) {
				return;
			}
			this._wikioasisPaginatorInitialized = true;

			// If a newer-style `update` method exists, call it to sync the
			// paginator UI with the store's current state.
			if ( typeof this.update === 'function' ) {
				this.update();
			}
		};
	}, function () {
		// ext.oOJSPlus.data is not registered on this wiki — nothing to patch.
	} );
}() );
