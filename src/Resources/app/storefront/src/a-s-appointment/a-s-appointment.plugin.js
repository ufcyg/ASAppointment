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
        window.onscroll = function() {
            if ((window.innerHeight + window.pageYOffset) >= document.body.offsetHeight) {
                alert(this.options.text);
            }
        };
    }
}