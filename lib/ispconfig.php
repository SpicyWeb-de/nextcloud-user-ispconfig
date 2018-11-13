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

	/**
	 * Create new IMAP authentication provider
	 *
	 * @param string $mailbox PHP imap_open mailbox definition, e.g.
	 *                        {127.0.0.1:143/imap/readonly}
	 * @param string $domain  If provided, loging will be restricted to this domain
	 */
    public function __construct($location, $uri, $user, $password, $options){
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
        list($uid, $mailbox, $domain) = $this->parseUID($uid);

		// Check, if domain is allowed
        if($this->allowedDomains){
            if(count($this->allowedDomains) && $domain && !in_array($domain, $this->allowedDomains))
                return false;
        }

		// check for existing user with this mail address
        $username = $this->checkExistingUser($uid, $mailbox, $domain);
        // Connect to ISPConfig API client
        $client = new SoapClient(null, array('location' => $this->soapLocation, 'uri' => $this->soapUri));
        try {
            //* Login to the remote server
            if($session_id = $client->login($this->remoteUser,$this->remotePassword)) {
                $mailbox = $client->mail_user_get($session_id, array('email' => $username));
                if(count($mailbox)){
                    $displayname = $mailbox[0]['name'];
                    $cryptedPassword = $mailbox[0]['password'];
                    $authResult = crypt($password, $cryptedPassword) === $cryptedPassword;
                }
                else
                    $authResult = false;
                //* Logout
                $client->logout($session_id);
            }
            else
                $authResult = false;
        } catch (SoapFault $e) {
            $authResult = false;
        }

        if ($authResult) {
            $uid = mb_strtolower($username);
            $this->storeUser($uid, $displayname, $this->getQuota($domain), $this->getGroups($domain));
            return $uid;
        } else {
            return false;
        }
	}

    /**
     * Check for a user already existing with this mail account and continue with his UID, if found
     *
     * @param string $uid username as entered by user
     * @param string $mailbox users mailbox name
     * @param string $domain users maildomain name
     *
     * @return string original username or the one found in DB
     * @throws \OC\DatabaseException
     */
	private function checkExistingUser($uid, $mailbox, $domain) {
        $result = OC_DB::executeAudited(
            'SELECT `userid` FROM `*PREFIX*preferences` WHERE `appid`=? AND `configkey`=? AND `configvalue`=?',
            array('settings','email',"$mailbox@$domain")
        );

        $users = array();
        while ($row = $result->fetchRow()) {
            $users[] = $row['userid'];
        }

        if(count($users) === 1) {
            $username = $users[0];
        }else{
            $username = $uid;
        }
        return $username;
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

        // Replace escaped @ symbol in uid (which is a mail address)
        // but only if there is no @ symbol and if there is a %40 inside the uid
        if (!(strpos($uid, '@') !== false) && (strpos($uid, '%40') !== false)) {
            $uid = str_replace("%40","@",$uid);
        }
        list($mailbox, $domain) = preg_split('/@/', $uid);

        return array($uid, $mailbox, $domain);
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
