<?php

    class SlackMixpanelCommand
    {
        private $msg = array(
            "auth_denied" => "not authenticated.",
            "current_users" => "Currently there are *%s* users.",
            "lastseen_users" => "*%s* users where using the app in the last %s.",
            "country_users" => "*%s* users are from %s.",
            "country_users_not_selected" => "please select a country (example: country AT)",
            "cmd_not_valid" => "'%s' is not valid command. see */mixpanel* help for more information.",
            "help" => "
*/mixpanel current*                     _gets the current amount of users_
*/mixpanel lastseen*                   _gets the amount of users in the last week_
*/mixpanel lastseen XXX*        _gets the amount of users in the last period (example: lastseen 1 week, lastseen 15 minutes)_
*/mixpanel country XXX*         _gets the amount of users in the country (example: country US, country AT)_
*/mixpanel help*                             _returns helpful information on how to use this command_
            "
        );

        //holds the data from the mixpanel api
        private $data;
        //holds the config
        private $config;

        function __construct($data, $config)
        {
            $this->data = $data;
            $this->config = $config;
        }

        //returns the calculated output of the given slack post request
        function get()
        {
            //obviously we check if the request was performed from slack
            //and if the user that requested it is also allowed to use this command
            if($this->isAuthenticated()) {
                return $this->format($this->perform());
            } else {
                return $this->msg["auth_denied"];
            }
        }

        //performs the actual commands
        private function perform()
        {
            //we use the text to further configure our actions
            $command = $this->data["text"];

            //if the user doesnt get how to use this, show the help
            if($this->needsHelp($command)) {
                return $this->msg["help"];
            }

            //fire up the mixpanel api
            $mp = new Mixpanel($this->config["mixpanel"]["key"], $this->config["mixpanel"]["secret"]);

            //gets the amount of current users
            if($command == "current") {
                return $this->getCurrentUsers($mp);
            }

            if (strpos($command, "lastseen") !== false) {
                $time = $this->getActionFromCommand($command, "lastseen", "1 week");
                return $this->getLastSeenUsers($mp, $time);
            }

            if (strpos($command, "country") !== false) {
                $country = $this->getActionFromCommand($command, "country", "");
                return $this->getUsersPerCountry($mp, $country);
            }

            //could not find a valid command
            return sprintf($this->msg["cmd_not_valid"], $command);
        }

        ////////////////// COMMANDS //////////////////

        private function getCurrentUsers($mp)
        {
            $response = $mp->request(array("engage/stats"), array());
            return sprintf($this->msg["current_users"], $response->results);
        }

        private function getLastSeenUsers($mp, $time)
        {
            //get the current date - identify and apply the correct format
            $lastSeen = date("c", strtotime("-" . $time));
            $lastSeen = explode("+", $lastSeen)[0];

            $response = $mp->request(array("engage/stats"), array(
                "selector" => 'properties["$last_seen"] >= "'. $lastSeen . '"'
            ));

            return sprintf($this->msg["lastseen_users"], $response->results, $time);
        }

        private function getUsersPerCountry($mp, $country)
        {
            if(strlen($country) == 0) {
                return $this->msg["country_users_not_selected"];
            } else {
                $response = $mp->request(array("engage/stats"), array(
                    "selector" => 'properties["$country_code"] == "'. $country . '"'
                ));
            }

            return sprintf($this->msg["country_users"], $response->results, $country);
        }

        ////////////////// HELPERS //////////////////

        //checks whether the user is authenticated to use this script
        private function isAuthenticated()
        {
            return in_array($this->data["user_id"], $this->config["slack"]["auth_users"]) && $this->data["token"] == $this->config["slack"]["token"];
        }

        //checks whether the user entered the help or empty command
        private function needsHelp($cmd)
        {
            return $cmd == "help" || strpos($cmd, "help") || strlen($cmd) == 0;
        }

        //tries to get the action from a command like lastseen 1 week (action = 1 week)
        //if that doesnt work, return the default value
        private function getActionFromCommand($string, $splitter, $default)
        {
            $split = explode($splitter, $string);

            if(count($split) > 1 && strlen($split[1]) > 1)
                return trim($split[1]);

            return $default;
        }

        //formats a given string to a valid slack response
        private function format($response)
        {
            header('Content-Type: application/json');

            $return = array(
                "response_type" => $this->config["slack"]["post_to_channel"] == 1 ? "in_channel" : "",
                "text" => $response
            );

            return json_encode($return);
        }
    }


    ////////////////// OFFICAL MP API CLIENT //////////////////

    class Mixpanel
    {
        private $api_url = 'http://mixpanel.com/api';
        private $version = '2.0';
        private $api_key;
        private $api_secret;

        public function __construct($api_key, $api_secret) {
            $this->api_key = $api_key;
            $this->api_secret = $api_secret;
        }

        public function request($methods, $params, $format='json') {
            // $end_point is an API end point such as events, properties, funnels, etc.
            // $method is an API method such as general, unique, average, etc.
            // $params is an associative array of parameters.
            // See http://mixpanel.com/api/docs/guides/api/

            if (!isset($params['api_key']))
                $params['api_key'] = $this->api_key;

            $params['format'] = $format;

            if (!isset($params['expire'])) {
                $current_utc_time = time();// - date('Z');
                $params['expire'] = $current_utc_time + 600; // Default 10 minutes
            }

            $param_query = '';
            foreach ($params as $param => &$value) {
                if (is_array($value))
                    $value = json_encode($value);
                $param_query .= '&' . urlencode($param) . '=' . urlencode($value);
            }

            $sig = $this->signature($params);

            $uri = '/' . $this->version . '/' . join('/', $methods) . '/';
            $request_url = $uri . '?sig=' . $sig . $param_query;

            $curl_handle=curl_init();
            curl_setopt($curl_handle, CURLOPT_URL, $this->api_url . $request_url);
            curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
            $data = curl_exec($curl_handle);
            curl_close($curl_handle);

            return json_decode($data);
        }

        private function signature($params) {
            ksort($params);
            $param_string ='';
            foreach ($params as $param => $value) {
                $param_string .= $param . '=' . $value;
            }

            return md5($param_string . $this->api_secret);
        }
    }

    ////////////////// MAIN //////////////////

    require "config.php";

    date_default_timezone_set($config["timezone"]);

    $smp = new SlackMixpanelCommand($_POST, $config);
    echo $smp->get();
?>
