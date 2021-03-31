import Plugin from 'src/plugin-system/plugin.class';

export default class ASAppointment extends Plugin {

    static options = {
        /**
         * Specifies the text that is prompted to the user
         * @type string
         */
        text: 'seems like there\'s nothing more to see here.',
    };

    init() {
        const that = this;
        // window.onscroll = function() {
        //     if ((window.innerHeight + window.pageYOffset) >= document.body.offsetHeight) {
        //         alert(that.options.text);
        //     }
        // };
        this.registerButton();
    };

    addLineItem = () => {
        alert('henlo');
    }

    registerButton() {
        const elements = document.getElementsByClassName('as-appointment-duplicate-item');
        [...elements].forEach((element) => element.addEventListener(
            "change",  
            this.addLineItem
        ));
    };



}