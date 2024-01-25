require([],
    function(){
        "use strict";

        $ = jQuery.noConflict();

        const destinationAccount = $('#destination-account');
        const destinationAccountCopy = destinationAccount.next().children().eq(0);
        const destinationAccountQrCode = destinationAccount.next().children().eq(1);
        const destinationTag = $('#destination-tag');
        const destinationTagCopy = destinationTag.next().children().eq(0);
        const destinationTagQrCode = destinationTag.next().children().eq(1);

        console.log(this)
        //destinationAccountCopy.on('click', copyToClipboard.bind(this, destinationAccount));
        //destinationTagCopy.on('click', copyToClipboard.bind(this, destinationTag));

    });
