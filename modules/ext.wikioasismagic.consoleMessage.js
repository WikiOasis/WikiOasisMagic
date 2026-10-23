( function () {
    'use strict';

    // art, loosely based on that in WikiOasis/salt motd module
    const b = 'font-family:monospace;line-height:1;';
    console.log(
        '%c\n %c     %c %c▃%c▆%c▝%c        %c\n %c    %c▗%c▘%c▄▄%c▅%c▝%c %c      %c\n %c    %c▏%c▅▅%c▗%c╵%c▅%c▇%c▄%c %c    %c\n %c %c %c▘%c %c▝%c╵%c▄%c▝%c▚%c▃%c╵%c▄%c▝%c▖   %c\n %c %c▍%c %c▝%c %c▝%c▃%c╵%c┊%c▅%c▃%c▅%c▂%c╵%c▝%c %c %c\n%c▗%c▖%c▝%c▁%c▃%c┊%c▅%c▂%c▇%c▄%c▂%c▄%c▇%c▅%c▇%c▄%c▝%c \n%c▎%c▄%c▝%c▂%c▄%c▂%c▅%c▆%c▇%c▅%c▂%c▆%c▃%c┊%c╵%c▅%c▝%c▋%c\n%c▏%c▄▄▄%c▆%c▄%c▆%c▃%c╵%c▄▄%c▇%c▄%c %c▄%c╴%c▝%c \n%c▍%c▄%c╵%c╴%c▄▅%c▇%c▖%c▇%c▖%c▃▃▃%c▝%c %c▝%c▄%c▎%c\n %c▖%c╵%c▃%c▅%c▃%c▃%c▘%c %c▘%c▄▄▄▄%c▍%c %c▗%c \n  %c▄%c▂%c╵%c▇%c▆%c▇%c▇%c▆%c▄▄▄%c▗%c %c▝%c %c %c\n %c  %c %c▅%c▃%c▂%c▁%c▁%c▁%c▁%c▃%c▅%c  %c   %c\n',
        b,b+'color:rgb(0,0,0);',b,b+'color:rgb(0,127,185);',b+'color:rgb(0,139,203);',b+'color:rgb(0,0,0);background:rgb(0,96,141);',
        b+'color:rgb(0,0,0);',b,b+'color:rgb(0,0,0);',b+'color:rgb(1,112,166);',b+'color:rgb(0,75,110);background:rgb(1,138,203);',b+'color:rgb(1,138,205);background:rgb(1,141,207);',
        b+'color:rgb(1,138,205);background:rgb(0,141,207);',b+'color:rgb(0,0,0);background:rgb(1,110,162);',b,b+'color:rgb(0,47,70);',b,b+'color:rgb(0,0,0);',
        b+'color:rgb(0,0,0);background:rgb(2,130,195);',b+'color:rgb(2,134,201);background:rgb(2,136,203);',b+'color:rgb(0,0,0);background:rgb(1,116,174);',b+'color:rgb(2,136,203);background:rgb(1,131,196);',b+'color:rgb(2,134,201);background:rgb(2,136,203);',b+'color:rgb(2,130,195);background:rgb(1,79,118);',
        b+'color:rgb(1,105,158);',b,b+'color:rgb(0,0,0);',b,b+'color:rgb(0,0,0);',b,
        b+'color:rgb(0,0,0);background:rgb(4,106,167);',b,b+'color:rgb(2,131,198);',b+'color:rgb(3,131,199);background:rgb(3,128,195);',b+'color:rgb(3,129,197);background:rgb(3,131,199);',b+'color:rgb(1,66,101);background:rgb(3,129,197);',
        b+'color:rgb(0,0,0);background:rgb(1,84,128);',b+'color:rgb(0,0,0);background:rgb(2,119,181);',b+'color:rgb(3,131,199);background:rgb(2,124,189);',b+'color:rgb(3,129,197);background:rgb(3,131,199);',b+'color:rgb(1,68,103);background:rgb(3,128,195);',b+'color:rgb(2,116,178);',
        b,b+'color:rgb(0,0,0);',b+'color:rgb(0,0,0);background:rgb(4,115,182);',b+'color:rgb(0,14,22);background:rgb(5,120,189);',b+'color:rgb(0,0,0);background:rgb(4,113,178);',b,
        b+'color:rgb(2,80,124);',b+'color:rgb(0,0,0);background:rgb(3,119,183);',b+'color:rgb(4,126,195);background:rgb(3,123,191);',b+'color:rgb(4,125,194);background:rgb(3,125,193);',b+'color:rgb(3,121,189);',b+'color:rgb(2,100,156);',
        b+'color:rgb(0,0,0);background:rgb(3,111,171);',b+'color:rgb(0,0,0);background:rgb(3,116,180);',b+'color:rgb(4,126,195);background:rgb(3,124,192);',b+'color:rgb(0,0,0);background:rgb(3,110,171);',b,b+'color:rgb(0,38,60);',
        b,b+'color:rgb(2,68,109);',b+'color:rgb(4,104,165);',b+'color:rgb(4,102,162);',b+'color:rgb(2,72,115);background:rgb(4,116,183);',b+'color:rgb(5,118,188);background:rgb(5,120,189);',
        b+'color:rgb(5,119,188);background:rgb(4,115,182);',b+'color:rgb(4,108,170);',b+'color:rgb(3,95,151);',b+'color:rgb(0,0,0);background:rgb(2,74,115);',b+'color:rgb(0,0,0);background:rgb(4,109,171);',b+'color:rgb(2,60,95);background:rgb(4,120,189);',
        b+'color:rgb(5,119,189);background:rgb(5,121,191);',b+'color:rgb(4,118,186);background:rgb(2,69,108);',b+'color:rgb(4,104,165);',b+'color:rgb(0,0,0);background:rgb(2,80,125);',b+'color:rgb(0,0,0);background:rgb(4,115,180);',b+'color:rgb(0,0,0);background:rgb(4,114,181);',
        b,b+'color:rgb(0,0,0);background:rgb(5,108,174);',b+'color:rgb(6,115,185);background:rgb(5,117,186);',b+'color:rgb(0,0,0);background:rgb(5,108,174);',b+'color:rgb(4,94,153);',b+'color:rgb(0,0,0);background:rgb(4,104,165);',
        b+'color:rgb(3,67,108);background:rgb(5,116,185);',b+'color:rgb(6,115,185);background:rgb(5,117,186);',b+'color:rgb(6,115,185);background:rgb(5,117,187);',b+'color:rgb(5,111,179);',b+'color:rgb(5,101,163);',b+'color:rgb(4,88,143);',
        b+'color:rgb(0,0,0);background:rgb(4,97,155);',b+'color:rgb(0,0,0);background:rgb(5,110,176);',b+'color:rgb(5,116,186);background:rgb(5,114,183);',b+'color:rgb(5,117,187);background:rgb(5,114,184);',b+'color:rgb(5,102,165);',b+'color:rgb(3,83,133);',
        b+'color:rgb(3,75,120);',b,b+'color:rgb(2,65,106);background:rgb(6,112,182);',b+'color:rgb(7,111,181);background:rgb(6,113,183);',b+'color:rgb(6,109,178);',b+'color:rgb(5,91,149);',
        b+'color:rgb(0,0,0);background:rgb(4,99,161);',b+'color:rgb(0,0,0);background:rgb(5,106,173);',b+'color:rgb(6,112,183);background:rgb(6,108,176);',b+'color:rgb(7,110,181);background:rgb(6,112,183);',b+'color:rgb(6,105,172);',b+'color:rgb(5,91,150);',
        b,b+'color:rgb(0,0,0);background:rgb(5,106,173);',b+'color:rgb(7,111,182);background:rgb(6,109,180);',b+'color:rgb(0,0,0);background:rgb(6,110,180);',b,b+'color:rgb(0,0,0);background:rgb(6,95,158);',
        b+'color:rgb(7,107,178);background:rgb(7,109,180);',b+'color:rgb(7,109,180);background:rgb(6,103,171);',b+'color:rgb(7,108,179);background:rgb(7,107,178);',b+'color:rgb(7,107,178);background:rgb(7,109,180);',b+'color:rgb(6,103,172);background:rgb(3,62,103);',b+'color:rgb(4,76,128);',
        b+'color:rgb(0,0,0);background:rgb(3,56,93);',b+'color:rgb(0,0,0);background:rgb(6,105,175);',b+'color:rgb(7,106,178);background:rgb(7,108,179);',b+'color:rgb(0,0,0);background:rgb(7,105,175);',b,b+'color:rgb(6,95,158);',
        b+'color:rgb(8,105,177);background:rgb(7,107,179);',b+'color:rgb(7,102,171);',b,b+'color:rgb(0,0,0);background:rgb(7,96,162);',b+'color:rgb(8,105,177);background:rgb(7,101,171);',b+'color:rgb(7,96,163);',
        b+'color:rgb(0,0,0);background:rgb(7,99,166);',b+'color:rgb(0,0,0);background:rgb(7,97,164);',b+'color:rgb(0,0,0);background:rgb(7,101,170);',b+'color:rgb(5,73,124);',b,b+'color:rgb(0,0,0);background:rgb(5,81,139);',
        b+'color:rgb(8,102,175);background:rgb(8,104,176);',b+'color:rgb(6,94,160);',b,b+'color:rgb(0,0,0);background:rgb(7,97,166);',b,b+'color:rgb(0,0,0);background:rgb(6,76,132);',
        b+'color:rgb(4,56,98);background:rgb(8,99,172);',b+'color:rgb(8,101,173);background:rgb(8,99,172);',b+'color:rgb(8,98,171);',b+'color:rgb(9,99,172);',b+'color:rgb(8,94,162);',b+'color:rgb(9,99,172);background:rgb(5,66,114);',
        b+'color:rgb(9,99,172);background:rgb(9,101,174);',b+'color:rgb(9,98,171);background:rgb(9,100,173);',b+'color:rgb(0,0,0);background:rgb(8,95,165);',b,b+'color:rgb(5,71,125);',b,
        b+'color:rgb(0,0,0);',b,b+'color:rgb(0,0,0);',b,b+'color:rgb(0,0,0);background:rgb(6,73,128);',b+'color:rgb(0,0,0);background:rgb(8,88,155);',
        b+'color:rgb(0,0,0);background:rgb(9,95,168);',b+'color:rgb(0,0,0);background:rgb(9,96,169);',b+'color:rgb(6,70,126);background:rgb(9,96,169);',b+'color:rgb(4,56,102);background:rgb(9,95,169);',b+'color:rgb(0,0,0);background:rgb(9,92,164);',b+'color:rgb(0,0,0);background:rgb(9,93,165);',
        b+'color:rgb(0,0,0);background:rgb(7,78,137);',b,b+'color:rgb(0,9,16);',b
    );

    const help = mw.message(
        'wikioasis-console-message'
    ).text();

    console.log(
        '%c' + help,
        'color: #018DCF; font-size: larger;',
    );
}() );