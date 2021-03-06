import AJAX from '../../AJAX.es6.js'
import Message from './Message.es6.js';
import Logs from './Logs.es6.js'
import Processes from './Processes.es6.js'

class SyndicatedTemplates {

  constructor () {
    this.refresh_view()
  }

  push_templates (e) {

    e.preventDefault()

    AJAX.post(DataSync.api.url + 'templates/start_sync').then(function (result) {

      let process = {
        id: btoa(Math.random().toString()),
        topic: 'Templates',
        running: true,
      }
      Processes.create(process)

      let admin_message = {}
      admin_message.process_id = process.id
      admin_message.success = true
      admin_message.topic = process.topic
      admin_message.message = 'Templates pushed.'
      Message.admin_message(admin_message)

      new SyndicatedTemplates();
      if ( DataSync.options.debug ) {
        let logs = new Logs()
        logs.refresh_log();
      }
    })

  }

  init () {
    let self = this;
    // PUSH TEMPLATES BUTTON PUSH
    if (document.getElementById('template_push')) {
      document.getElementById('template_push').onclick = function (e) {

        document.querySelector('#templates_wrap').classList.add('hidden');
        document.querySelector('#templates .loading_spinner').classList.remove('hidden');
        self.push_templates(e)
      }
    }


  }

  refresh_view() {

    let self = this;
    // LOAD TEMPLATE LIST
    if (document.getElementById('templates_wrap')) {
      AJAX.get_html(DataSync.api.url + 'settings_tab/templates' ).then(function( result) {
        Message.display_html( result, 'templates', 'Templates' )
        document.querySelector('#templates_wrap').classList.remove('hidden');
        self.init();
      });
    }
  }

}

export default SyndicatedTemplates;