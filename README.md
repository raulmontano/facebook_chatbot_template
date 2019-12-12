### OBJECTIVE
This template has been implemented in order to develop Facebook bots that consume from the Inbenta Chatbot API with the minimum configuration and effort. It uses some libraries to connect the Chatbot API with Facebook Messenger. The main library of this template is Facebook Connector, which extends from a base library named [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector), built to be used as a base for different external services like Skype, Line, etc.

This template includes **/conf** and **/lang** folders, which have all the configuration and translations required by the libraries, and a small file **server.php** which creates a FacebookConnector’s instance in order to handle all the incoming requests.

### FUNCTIONALITIES
This bot template inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options with a limit of 3 elements (Facebook only allows a maximum number of three options)
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat after a number of negative ratings
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms
* Custom FAQ title in button when displaying multiple options
* Retrieve Facebook tokens from ExtraInfo
* Send a button that opens a configured URL along with the answer
* Retrieve translation labels from ExtraInfo

### INSTALLATION
It's pretty simple to get this UI working, you can find all the documentation in the file [Instructions.pdf](Instructions.pdf) located in the repository root. The mandatory configuration files are included by default in `/conf/custom` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'**
    Provide the API Key and API Secret of your Chatbot Instance.

* **File 'environments.php'**
    Here you can define regexes to detect `development` and `preproduction` environments. If the regexes do not match the current conditions or there isn't any regex configured, `production` environment will be assumed.

Also, this template needs two Facebook tokens: `verify_token` and `page_access_token` that will be retrieved from ExtraInfo. Here are the steps to create the full ExtraInfo object:

* Go to **Knowledge -> Extra Info -> Manage groups and types** and click on **Add new group**. Name it `facebook`.
* Go to **Manage groups and types -> facebook -> Add type**. Name it `verify_token`, add a new property named `value` with type `text` and save.
* Go to **Manage groups and types -> facebook -> Add type**. Name it `page_tokens`.
* Add three properties named `development`, `preproduction` and `production` with type `text` and save.

Now, create the ExtraInfo objects by clicking the **New entry** button. You can select these two new types: `verify_token` and `page_tokens`. Create the two objects naming them as `verify_token` and `page_tokens` respectively. Then, fill the information and remember to publish ExtraInfo by clicking the **Post** button.

### HOW TO CUSTOMIZE
**From configuration**

For a default behavior, the only requisite is to fill the basic configuration (more information in `/conf/README.md`). There are some extra configuration parameters in the configuration files that allow you to modify the basic-behavior.


**Custom Behaviors**

If you need to customize the bot flow, you need to extend the class `FacebookConnector`, included in the `/lib/FacebookConnector` folder. You can modify 'FacebookConnector' methods and override all the parent methods from `ChatbotConnector`.

For example, when the bot is configured to escalate with an agent, a conversation in HyperChat starts. If your bot needs to use an external chat service, you should override the parent method `escalateToAgent` and set up the external service:
```php
	//Tries to start a chat with an agent with an external service
	protected function escalateToAgent()
	{
		$useExternalService = $this->conf->get('chat.useExternal');

		if ($useExternalService) {
		    // Inform the user that the chat is being created
			$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));

		    // Create a new instance for the external client
		    $externalChat = New SomeExternalChatClass($this->conf->get('chat.externalConf'));
			$externalChat->openChat();
		} else {
			// Use the parent method to escalate to HyperChat
			parent::escalateToAgent();
		}
	}
```

### DEPENDENCIES
This application imports [`inbenta/chatbot-api-connector`](https://github.com/inbenta-integrations/chatbot_api_connector) as a Composer dependency, that includes `symfony/http-foundation@^3.1` and `guzzlehttp/guzzle@~6.0` as dependencies too.
