<?php
/*
 * Provides method for querying Google Apps email account information
 */

require_once 'google-api-php-client/src/Google/autoload.php';

class GoogleAppsEmail {

    /**
     * @param $logonid
     * @return array
     */
    public static function _query($logonid) {

        // Get secrets file.  This contains OAuth2.0 secrets
        $file = file_get_contents("bol_secrets.json");
        $json = (object)json_decode($file, true);

        // Authenticate with Google Directory API, and start a Directory API client.
        $client = new Google_Client();
        $client->setApplicationName("BOL Directory API");
        $service = new Google_Service_Directory($client);

        // Reuse Token if it's available.
        if (isset($_SESSION['service_token'])) {
            $client->setAccessToken($_SESSION['service_token']);
        }

        $key = file_get_contents($json->client_key_path);
        $cred = new Google_Auth_AssertionCredentials(
            $json->client_email,
            array($json->client_scope, 'https://apps-apis.google.com/a/feeds/emailsettings/2.0/'),
            $key,
            'notasecret',
            'http://oauth.net/grant_type/jwt/1.0/bearer',
            $json->client_delegate
        );
        $client->setAssertionCredentials($cred);
        if ($client->getAuth()->isAccessTokenExpired()) {
            $client->getAuth()->refreshTokenWithAssertion($cred);
        }

        // Save session.
        $_SESSION['service_token'] = $client->getAccessToken();

        // Try to get directory data for a user.  This will fail if the
        // user does not exist.
        try {
            $user = $service->users->get($logonid . '@g.ucla.edu');

        } catch (Exception $err) {
            return array('Errors' => $err->getMessage());
        }

        // The uses exists, so now we can set up Email Settings API authentication.

        // We'll reuse the token.
        $json = json_decode($client->getAccessToken());
        $token = $json->access_token;
        $auth = "Authorization: Bearer=" . $token;

        // This is re-used code.  The Email Settings API does not have a a `google-api-php-client` Service,
        // so we use curl to get data.  Note that we pass in the 'access_token' in the URL.
        $nodes = array();
        foreach (array('sendas', 'forwarding', 'pop', 'imap', 'vacation', 'delegation') as $setting_id) {
            $nodes[$setting_id] = sprintf("https://apps-apis.google.com/a/feeds/emailsettings/2.0/g.ucla.edu/%s/%s?alt=json&access_token=%s", $logonid, $setting_id, $token);
        }

        // Set up the curl request.
        $errors = array();
        $curl_array = array();

        $mh = curl_multi_init();
        foreach ($nodes as $i => $url) {
            $curl_array[$i] = curl_init($url);
            curl_setopt($curl_array[$i], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_array[$i], CURLOPT_HTTPHEADER, array($auth));
            curl_multi_add_handle($mh, $curl_array[$i]);
        }

        $running = NULL;

        do {
            usleep(10000);
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        $results = array();
        foreach ($nodes as $i => $url) {
            $http_code = curl_getinfo($curl_array[$i], CURLINFO_HTTP_CODE);
            if ($http_code == 200) {
                $results[$i] = json_decode(curl_multi_getcontent($curl_array[$i]));
            } else {
                $response = curl_multi_getcontent($curl_array[$i]);
                var_dump($response);
                @$xml = simplexml_load_string($response);
                if (!$xml) {
                    $reason = htmlentities($response);
                } else {
                    $reason = htmlentities($xml->error['reason']);
                }
                $errors['Error ' . $http_code] = $reason;
            }
            curl_multi_remove_handle($mh, $curl_array[$i]);
        }
        curl_multi_close($mh);

        // If we hit any errors here, stop execution.
        if (!empty($errors)) {
            return array('Errors' => $errors);
        }

        // Finally, we have a result...  we can start to prepare response.
        $r = array();

        // This data is from the Directory API
        $r['username'] = $logonid;
        $r['fullname'] = $user->name->familyName . ", " . $user->name->givenName;
        $r['suspended'] = $user->suspended;

        $r['sendas'] = array($logonid . '@g.ucla.edu');

        // This following data is from the Email Settings API.  The code below is
        // from previous implementation.
        if (!empty($results['sendas']->feed->entry)) {
          $additional_default = false;

          foreach ($results['sendas']->feed->entry as $k => $v) {
            foreach ($v->{'apps$property'} as $w) {
              $additional_sendas[$k][$w->name] = $w->value;
            }
          }

          foreach ($additional_sendas as $v) {
            $temp = '';

            if ($v['verified'] != 'true') {
              $temp .= '[Unverified] ';
            }

            if ($v['isDefault'] == 'true') {
              $additional_default = true;
              $temp .= '[Default] ';
            }

            $temp .= $v['name'] . ' &lt;' . $v['address'] . '&gt;';

            if (!empty($v['replyTo'])) {
              $temp .= ' (Reply-to: ' . $v['replyTo'] . ')';
            }

            $r['sendas'][] .= $temp;
          }

          if (!$additional_default) {
            $r['sendas'][0] .= ' [Default]';
          }
        }

        $r['pop'] = 'Disabled';

        if (!empty($results['pop']->entry->{'apps$property'})) {
          foreach ($results['pop']->entry->{'apps$property'} as $v) {
            if ($v->name == 'enable') {
              if ($v->value == 'true') {
                $r['pop'] = 'Enabled';
              }
            } elseif ($v->name == 'action') {
              $pop_action = ' (Action: ' . $v->value . ')';
            }
          }

          if ($r['pop'] == 'Enabled') {
            $r['pop'] .= $pop_action;
          }
        }

        $r['imap'] = 'Disabled';

        if (!empty($results['imap']->entry->{'apps$property'})) {
          foreach ($results['imap']->entry->{'apps$property'} as $v) {
            if ($v->name == 'enable' && $v->value == 'true') {
              $r['imap'] = 'Enabled';
              break;
            }
          }
        }

        $r['forwarding'] = 'Disabled';

        if (!empty($results['forwarding']->entry->{'apps$property'})) {
          $forwarding_on = false;
          foreach ($results['forwarding']->entry->{'apps$property'} as $v) {
            if ($v->name == 'enable' && $v->value == 'true') {
              $forwarding_on = true;
              continue;
            }
            $forwarding_settings[$v->name] = $v->value;
          }
          if ($forwarding_on) {
            $r['forwarding'] = array($forwarding_settings['forwardTo'] . ' (Action: ' . $forwarding_settings['action'] . ')');
          }
        }

        $r['vacation'] = 'Disabled';

        if (!empty($results['vacation']->entry->{'apps$property'})) {
          foreach ($results['vacation']->entry->{'apps$property'} as $v) {
            $vacation_settings[$v->name] = $v->value;
          }

          if ($vacation_settings['enable'] == 'true') {
            $temp = array();
            $temp[] = 'Start: ' . $vacation_settings['startDate'];
            if (!empty($vacation_settings['endDate'])) {
              $temp[] = 'End: ' . $vacation_settings['endDate'];
            }

            if ($vacation_settings['contactsOnly'] == 'true') {
              $temp[] = '+Only send to people in Contacts';
            }

            if ($vacation_settings['domainOnly'] == 'true') {
              $temp[] = '+Only send to people in UCLA';
            }

            $temp[] = 'Subject: ' . $vacation_settings['subject'];
            $temp[] = 'Message: ' . $vacation_settings['message'];

            $r['vacation'] = $temp;
          }
        }

        $r['delegation'] = array('Disabled');

        if (!empty($results['delegation']->feed->entry)) {
          $r['delegation'] = array();

          foreach ($results['delegation']->feed->entry as $k => $v) {
            foreach ($v->{'apps$property'} as $w) {
              $r['delegation'][$k][$w->name] = trim($w->value);
            }
          }

          foreach ($r['delegation'] as &$v) {
            $temp = '';
            if (!empty($v['delegate'])) {
              $temp = $v['delegate'] . ' ';
            }
            $v = sprintf('%s<%s> (Status: %s)', $temp, $v['address'], $v['status']);
          }
        }

        return $r;
  }
}

