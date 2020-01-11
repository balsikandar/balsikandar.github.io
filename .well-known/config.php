<?php

    $config = array(
        //timezone that is used to calculate last seen
        "timezone" => "Asia/Kolkata",

        //mixpanel configuration
        "mixpanel" => array(
            "key"=> "e94150fc8f32e9e9c08737ac66ce5755",
            "secret" => "00635b8348483c8fab1013412df60e27"
        ),

        //slack configuration
        "slack" => array(
            //auth token of the custom command
            "token" => "eOibbumcAcOyfv350yUTDg8J",

            //whether to post publicly or to self only
            "post_to_channel" => 1,

            //id's of users that are allowed to use this command
            //otherwise will return not authenticated message.
            "auth_users" => array(
            )
        )
    );

?>
