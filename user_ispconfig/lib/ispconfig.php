<?php
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
    /**
     * @var array Mappings to convert bare, prefixed and suffixed uids back to mail addresses
     */
    private $uidMapping = array();
    /**
     * @var array Mappings to convert mail addresses to bare, prefixed or suffixed uids
     */
    private $mailMapping = array();

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
                if (array_key_exists('bare-name', $opts) && $opts['bare-name']) {
                    $this->uidMapping[$domain] = '/(.*)/';
                    $this->mailMapping[$domain] = '$1';
                } elseif (array_key_exists('uid-prefix', $opts)){
                    $this->uidMapping[$domain] = "/".$opts['uid-prefix']."(.*)/";
                    $this->mailMapping[$domain] = $opts['uid-prefix'] . "$1";
                }elseif (array_key_exists('uid-suffix', $opts)) {
                    $this->uidMapping[$domain] = "/(.*)".$opts['uid-suffix']."/";
                    $this->mailMapping[$domain] = "$1" . $opts['uid-suffix'];
                }
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
        if(!class_exists("SoapClient")){
            OCP\Util::writeLog('user_ispconfig', 'ERROR: PHP soap extension is not installed or not enabled', OCP\Util::ERROR);
            return false;
        }

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
            } else {
                OCP\Util::writeLog('user_ispconfig', 'SOAP error: Could not establish SOAP session', OCP\Util::ERROR);
            }
        } catch (SoapFault $e) {
            $errorMsg = $e->getMessage();
            switch($errorMsg) {
                case 'looks like we got no XML document':
                    OCP\Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid location or uri of ISPConfig SOAP Endpoint', OCP\Util::ERROR);
                    break;
                case 'The login failed. Username or password wrong.':
                    OCP\Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid credentials of ISPConfig remote user', OCP\Util::ERROR);
                    break;
                case 'You do not have the permissions to access this function.':
                    OCP\Util::writeLog('user_ispconfig', 'SOAP Request failed: ISPConfig remote user not allowed to access E-Mail User Functions', OCP\Util::ERROR);
                    break;
                default:
                    OCP\Util::writeLog('user_ispconfig', 'SOAP Request failed: ('.$e->getCode().')'.$e->getMessage(), OCP\Util::ERROR);
                    break;
            }
            $authResult = false;
        }

        if ($authResult) {
            $this->storeUser($authResult['uid'], $authResult["mailbox"], $authResult['domain'], $authResult['displayname'], $this->getQuota($authResult['domain']), $this->getGroups($authResult['domain']));
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
                return array("uid" => $uid, "mailbox" => $mailbox, "domain" => $domain, "displayname" => $displayname);
        }
        return false;
    }

       /**
     * Get UID, mailbox name and maildomain name from users entered UID
     *
     * @param string $uid
     * @return array array of multiple possible uid, mailbox and domain as strings
     * @throws \OC\DatabaseException
     */
	private function parseUID($uid) {
	    // Get existing user from DB
        $returningUser = $this->getUserData($uid);
        if($returningUser) {
            return array(array($returningUser['uid'], $returningUser['mailbox'], $returningUser['domain']));
        }

        // Make uid lower case
        $uid = strtolower($uid);
        $result = array();

        // Replace escaped @ symbol in uid (which is a mail address)
        // but only if there is no @ symbol and if there is a %40 inside the uid
        if (!(strpos($uid, '@') !== false) && (strpos($uid, '%40') !== false)) {
            $uid = str_replace("%40","@",$uid);
        }
        list($mailbox, $domain) = array_pad(preg_split('/@/', $uid), 2, false);


        if($domain){
            // UID is an email address
            if(array_key_exists($domain, $this->mailMapping)){
                // re-map uid if options set for this domain
                $uid = preg_filter("/(.*)/", $this->mailMapping[$domain], $mailbox, 1);
                $result[] = array($uid, $mailbox, $domain);
            } else {
                // just take "as is" if not
                $result[] = array($uid, $mailbox, $domain);
            }
        } else {
            // UID is no mail address
            // check for prefix, suffix or bare-name domains valid for this input
            foreach($this->uidMapping AS $mappingDomain => $pattern) {
               if ($mappedMailbox = preg_filter($pattern, '$1', $uid))
                    $result[] = array($uid, $mappedMailbox, $mappingDomain);
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
