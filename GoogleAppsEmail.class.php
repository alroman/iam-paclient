<?php
/*
 * Provides method for querying Google Apps email account information
 */

class GoogleAppsEmail {

    /**
     * @param $logonid
     * @return array
     */
    public static function _query($logonid) {

        // Get secrets file.
        $file = file_get_contents("bol_secrets.json");
        $json = json_decode($file, true);

        // Init Google Directory API auth
        $client = new Google_Client();
        $client->setApplicationName("BOL Directory API");
        $service = new Google_Service_Directory($client);

        // Reuse Token
        if (isset($_SESSION['service_token'])) {
            $client->setAccessToken($_SESSION['service_token']);
        }

        $key = file_get_contents($json->client_key_path);
        $cred = new Google_Auth_AssertionCredentials(
            $json->client_email,
            array($json->client_scope),
            $key,
            'notasecret',
            'http://oauth.net/grant_type/jwt/1.0/bearer',
            $json->client_delegate
        );
        $client->setAssertionCredentials($cred);
        if ($client->getAuth()->isAccessTokenExpired()) {
            $client->getAuth()->refreshTokenWithAssertion($cred);
        }

        // Save session
        $_SESSION['service_token'] = $client->getAccessToken();

        $results = array();

        try {
            $user = $logonid;
            $results = $service->users->get('alroman@g.ucla.edu');

        } catch (Exception $err) {
            return array('Errors' => $err->getMessage());
        }

        // We have a result...

        $r = array();

    $r['username'] = $results['account']->entry->{'apps$login'}->userName;
    $r['fullname'] = sprintf('%s, %s', $results['account']->entry->{'apps$name'}->familyName, $results['account']->entry->{'apps$name'}->givenName);
    $r['suspended'] = $results['account']->entry->{'apps$login'}->suspended;

    $r['sendas'] = array($results['account']->entry->{'apps$login'}->userName . '@g.ucla.edu');

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

