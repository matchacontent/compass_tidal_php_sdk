<?php
class RootsRatedWebhook
{

    function getAllHeaders()
    {
        $headers = array();
        $copy_server = array(
            'CONTENT_TYPE'   => 'Content-Type',
            'CONTENT_LENGTH' => 'Content-Length',
            'CONTENT_MD5'    => 'Content-Md5',
        );
        foreach ($_SERVER as $key => $value) 
        {
            if (substr($key, 0, 5) === 'HTTP_') 
            {
                $key = substr($key, 5);
                if (!isset($copy_server[$key]) || !isset($_SERVER[$key])) 
                {
                    $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                    $headers[$key] = $value;
                }
            } 
            elseif (isset($copy_server[$key])) 
            {
                $headers[$copy_server[$key]] = $value;
            }
        }
        if (!isset($headers['Authorization'])) 
        {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) 
            {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } 
            elseif (isset($_SERVER['PHP_AUTH_USER'])) 
            {
                $basic_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass);
            } 
            elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) 
            {
                $headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
            }
        }
        return $headers;
    }

    function executeHook($headers, $reqBody, $posts, $sdk)
    {
        $hookSignature = array_key_exists("X-Tidal-Signature", $headers) ?$headers["X-Tidal-Signature"] : false;
        $hookName = array_key_exists("X-Tidal-Event", $headers) ? $headers["X-Tidal-Event"] : false;

        if ( $sdk->validateHookSignature($reqBody, $hookSignature) && strlen($hookName) > 0)
        {
            $jsonHook = $reqBody ? json_decode($reqBody, true) : '';
            if (is_array($jsonHook)) 
            {
	   
                if ($sdk->isAuthenticated() ) 
                {
                    $hookName = $jsonHook['hook'];
                    $result = $this->parseHook($jsonHook, $hookName, $posts, $sdk);
                    if($result === true) 
                    {
                         $this->HTTPStatus(200, ' 200 OK');
                        echo('{"message":"ok"}');
                        flush();
                        return true;
                    }
                    else
                    {

                        if(gettype($result) === 'string')
                        {
                            echo($result);
                            $this->HTTPStatus(200, '200 OK');
                            return $result;
                        }
                        $this->HTTPStatus(401, '401 Invalid Hook Name');
                        return false;
                    }
                } 
                else 
                {
                    echo 'FALSE';
                    $this->HTTPStatus(401, '401 No Key and/or Secret');
                    return false;
                }
            }
            else 
            {
	         echo 'FALSE';
                $this->HTTPStatus(500, '500 Failed');
                return false;
            }
        } 
        else 
        { 
            echo 'FALSE';
            $this->HTTPStatus(401, '401 Invalid Hook Signature');
            return false;
        }
    }

    public function parseHook($jsonHook, $hookName, $posts, $sdk)
    {
        switch ($hookName) 
        {
	     case "distribution_schedule" : $this->postScheduling($jsonHook, $posts,$sdk); break;
            case "distribution_go_live" : $this->postGoLive($jsonHook, $posts, $sdk); break;
            case "content_update" :  $this->postRevision($jsonHook, $posts, $sdk); break;                   
            case "distribution_update" :  $this->postUpdate($jsonHook, $posts, $sdk); break; 
            case "distribution_revoke" : $this->postRevoke($jsonHook, $posts, $sdk);break;
            case "service_cancel" : $posts->deactivationPlugin(); break;
            case "service_phone_home" : $result = $this->servicePhoneHome($posts, $sdk); return $result; break;
            default : return false;
        }

        return true;
    }

    private function postScheduling($jsonHook, $posts, $sdk)
    {
        if(!array_key_exists('distribution', $jsonHook)) 
        {
            return false;
        }
        $rrId = trim($jsonHook['distribution']['id']);

        $data = $sdk->getData('content/' . $rrId);
        if (!$data) 
        {
            return false;
        }

        $distribution = $data['response']['distribution'];
        $catName = $sdk->getCategoryName();
        $postType = $sdk->getPostType();

        return $posts->postScheduling($distribution, $rrId, $catName, $postType);
    }

    private function postGoLive($jsonHook, $posts, $sdk)
    {
        if(!array_key_exists('distribution', $jsonHook)) 
        {
            return false;
        }

        $rrId = trim($jsonHook['distribution']['id']);

        if (empty($postId)) 
        {
    
            $data = $sdk->getData('content/' . $rrId);
            if (!$data) 
            {
                return false;
            }
        }

        $distribution = $data['response']['distribution'];
        $launchAt = $jsonHook['distribution']['launch_at'];
        $catName = $sdk->getCategoryName();
        $postType = $sdk->getPostType();

        return $posts->postGoLive($distribution, $launchAt, $rrId, $catName, $postType);
        }

    public function postRevision($jsonHook, $posts, $sdk)
    {
        $data = $sdk;
        $rrId = trim($jsonHook['distribution']['id']);
        $tempPost = $data->getData('content/' . $rrId);
        if (!$tempPost) 
        {
            return false;
        }

        $distribution = $tempPost['response']['distribution'];
        $scheduledAt = $distribution['distribution']['scheduled_at'];
        $postType = $sdk->getPostType();

        return $posts->postRevision($distribution, $rrId, $postType, $scheduledAt);
    }

    public function postUpdate($jsonHook, $posts, $sdk)
    {
        $data = $sdk;
        $rrId = trim($jsonHook['distribution']['id']);

        $tempPost = $data->getData('content/' . $rrId);
        if (!$tempPost) 
        {
            return false;
        }

        $distribution = $tempPost['response']['distribution'];
        $scheduledAt = $distribution['distribution']['scheduled_at'];
        $postType = $sdk->getPostType();

        return $posts->postUpdate($distribution, $rrId, $postType, $scheduledAt);
    }

    public function postRevoke($jsonHook, $posts, $sdk)
    {
        $rrId = trim($jsonHook['distribution']['id']);
        $postType = $sdk->getPostType();
        
        return $posts->postRevoke($rrId, $postType);
    }

    public function servicePhoneHome($options, $sdk) {
        if(!$sdk->isAuthenticated()) { 
            return false;
        }

        if ( !class_exists('Requests') ) {
            require_once 'vendor/rmccue/requests/library/Requests.php';
        }
        Requests::register_autoloader();
        $payload = $this->phoneHome($options, $sdk);
        $headers = array(
          'Content-Type: application/json',
          'Authorization: Basic '. $sdk->getBasicAuth(),
        );
        $url = $sdk->getPhoneHomeUrl() . $sdk->getToken() . '/phone_home';
        $request = Requests::post($url, $headers, $payload);

        $response = json_decode($request->body, true);
        $success = $results["success"];

        if ($success){
            return $request;
        } else {
            return $results;
        }
    }


    public function phoneHome($posts, $sdk) {
        $options = $posts->getInfo();

        $plugins = $options['plugins']; 
        $pluginsJSON = array();
        foreach ($plugins as $plugin) {
            $item = array();
            $item['name'] = $plugin['Name'];
            $item['version'] = $plugin['Version'];
            $pluginsJSON[] = $item;
        }

        $system_info = array();
        $system_info['platform_version'] = $options['db_version'];
        $system_info['php_version'] = phpversion();
        $system_info['root_url'] = $options['home'];
        $system_info['plugin_url'] = $options['plugins_url'];
        $system_info['installed_plugins'] = $pluginsJSON;

        $channel = array();
        $channel['token'] = $sdk->getToken();
        $channel['can_create_article'] = $options['publish_posts'];
        $channel['can_revoke_article'] = $options['delete_published_posts'];

        $checks = array();
        $checks['machine_user_present'] = $options['username_exists'];
        $checks['default_category_present'] = $options['category_exists'];

        $payload = array();
        $payload['system_info'] = $system_info;
        $payload['channel'] = $channel;
        $payload['checks'] = $checks;

        return $payload;
    }

    public function HTTPStatus($code, $message)
    {
        if (version_compare(phpversion(), '5.4.0', '>=')) 
        {
            http_response_code($code);
        } 
        else 
        {
            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
            header($protocol . ' ' . $message);
        }
    }

}
