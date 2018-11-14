<?php
use OCP\IDBConnection;
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

/**
 * User authentication against an ISPConfig 3 API server
 *
 * @category Apps
 * @package  UserISPConfig
 * @author   Michael Fürmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://spicyhub.de/spicy-web/nextcloud-user-ispconfig
 */
class OC_User_ISPCONFIG extends \OCA\user_ispconfig\Base {
    private $soapLocation;
    private $soapUri;
    private $remoteUser;
    private $remotePassword;
    private $options = array();

    // extracted from options
    private $allowedDomains = false;
    private $quota = false;
    private $groups = array();
    private $uidMapping = array();

	/**
	 * Create new IMAP authentication provider
	 *
	 * @param string $mailbox PHP imap_open mailbox definition, e.g.
	 *                        {127.0.0.1:143/imap/readonly}
	 * @param string $domain  If provided, loging will be restricted to this domain
	 */
    public function __construct($location, $uri, $user, $password, $options = array()){
        $this->soapLocation = $location;
        $this->soapUri = $uri;
        $this->remoteUser = $user;
        $this->remotePassword = $password;
        $this->options = $options;
        if(array_key_exists('allowed_domains', $options))
            $this->allowedDomains = $options['allowed_domains'];
        if(array_key_exists('default_quota', $options))
            $this->quota = $options['default_quota'];
        if(array_key_exists('default_groups', $options))
            $this->groups = $options['default_groups'];
        if(is_array($options['domain_config'])) {
            foreach($options['domain_config'] AS $domain => $opts) {
                if (array_key_exists('bare-name', $opts) && $opts['bare-name'])
                    $this->uidMapping[$domain] = '/(.*)/';
                elseif (array_key_exists('uid-prefix', $opts))
                    $this->uidMapping[$domain] = "/".$opts['uid-prefix']."(.*)/";
                elseif (array_key_exists('uid-suffix', $opts))
                    $this->uidMapping[$domain] = "/(.*)".$opts['uid-suffix']."/";
            }
        }
	}

	/**
	 * Check if the password is correct without logging in the user
	 *
	 * @param string $uid      The username
	 * @param string $password The password
	 *
	 * @return true/false
     * @throws \OC\DatabaseException
	 */
	public function checkPassword($uid, $password) {
        // get logins to check against the soap api
	    $logins = $this->parseUID($uid);

	    $authResult = false;
        $client = new SoapClient(null, array('location' => $this->soapLocation, 'uri' => $this->soapUri));
        try {
            if($session_id = $client->login($this->remoteUser,$this->remotePassword)) {
                foreach($logins AS $login) {
                    list($uid, $mailbox, $domain) = $login;
                    if($domainUser = $this->tryDomainLogin($uid, $mailbox, $domain, $password, $client, $session_id)) {
                        $authResult = $domainUser;
                        break;
                    }
                }
                $client->logout($session_id);
            }
        } catch (SoapFault $e) {
            $authResult = false;
        }

        if ($authResult) {
            $this->storeUser($authResult['uid'], $authResult["email"], $authResult['displayname'], $this->getQuota($authResult['domain']), $this->getGroups($authResult['domain']));
            return $authResult['uid'];
        } else {
            return false;
        }
	}

	private function tryDomainLogin($uid, $mailbox, $domain, $password, $soapClient, $soapSession){
        // Check, if domain is allowed
        if($this->allowedDomains){
            if(count($this->allowedDomains) && $domain && !in_array($domain, $this->allowedDomains)){
                return false;
            }
        }

        $mailuser = $soapClient->mail_user_get($soapSession, array('email' => "$mailbox@$domain"));
        if(count($mailuser)){
            $displayname = $mailuser[0]['name'];
            $cryptedPassword = $mailuser[0]['password'];
            if(crypt($password, $cryptedPassword) === $cryptedPassword)
                return array("uid" => $uid, "domain" => $domain, "email" => "$mailbox@$domain", "displayname" => $displayname);
        }
        return false;
    }

       /**
     * Get UID, mailbox name and maildomain name from users entered UID
     *
     * @param string $uid
     * @return array uid, mailbox and domain as strings
     */
	private function parseUID($uid) {
        // Make uid lower case
        $uid = strtolower($uid);
        $result = array();

        // Replace escaped @ symbol in uid (which is a mail address)
        // but only if there is no @ symbol and if there is a %40 inside the uid
        if (!(strpos($uid, '@') !== false) && (strpos($uid, '%40') !== false)) {
            $uid = str_replace("%40","@",$uid);
        }
        list($mailbox, $domain) = preg_split('/@/', $uid);


        if($domain){
            if(array_key_exists($domain, $this->uidMapping)){
                // re-map uid if options set for this domain
                $uid = preg_replace("/\*/", $uid, $this->uidMapping[$domain]);
                $result[] = array($uid, $mailbox, $domain);
            } else {
                // just take "as is" if not
                $result[] = array($uid, $mailbox, $domain);
            }
        } else {
            // UID is no mail address
            // check for prefix, suffix or bare-name domains valid for this input
            foreach($this->uidMapping AS $domain => $pattern) {
               if ($mappedMailbox = preg_filter($pattern, '$1', $uid))
                    $result[] = array($uid, $mappedMailbox, $domain);
            }
        }

        return $result;
    }

    /**
     * Get domain specific or global quota for a specific domain
     *
     * @param string $domain the domain
     * @return bool|string false or quota definition
     */
    private function getQuota($domain) {
        if(array_key_exists('domain_config', $this->options) &&
                array_key_exists($domain, $this->options['domain_config']) &&
                array_key_exists('quota', $this->options['domain_config'][$domain]))
            return $this->options['domain_config'][$domain]['quota'];
        return $this->quota;
    }

    /**
     * Get domain specific or global initial groups for a specific domain
     *
     * @param string $domain the domain
     * @return bool|array false or string array with group names
     */
    private function getGroups($domain) {
        if(array_key_exists('domain_config', $this->options) &&
                array_key_exists($domain, $this->options['domain_config']) &&
                array_key_exists('groups', $this->options['domain_config'][$domain]))
            return $this->options['domain_config'][$domain]['groups'];
        return $this->quota;
    }


}
