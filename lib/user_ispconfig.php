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
class OC_User_ISPCONFIG extends \OCA\user_ispconfig\ISPConfig_SOAP
{
  /**
   * @var array Config options from authenticator config
   */
  private $options = array();

  // extracted from options
  private $allowedDomains = false;
  private $quota = false;
  private $groups = array();
  private $preferences = array();
  /**
   * @var array Mappings to convert bare, prefixed and suffixed uids back to mail addresses
   */
  private $uidMapping = array();
  /**
   * @var array Mappings to convert mail addresses to bare, prefixed or suffixed uids
   */
  private $mailMapping = array();

  /**
   * Create new ISPConfig mailuser authenticator for nextcloud users.
   * @param $location SOAP location of ISPConfig remote API
   * @param $uri SOAP uri of ISPConfig remote API
   * @param $user Remote user for ISPConfig remote API
   * @param $password Remote users password
   * @param array $options Detailed config options from connector configuration in config.php
   */
  public function __construct($location, $uri, $user, $password, $options = array())
  {
    parent::__construct($location, $uri, $user, $password);
    $this->options = $options;
    if (array_key_exists('allowed_domains', $options))
      $this->allowedDomains = $options['allowed_domains'];
    if (array_key_exists('default_quota', $options))
      $this->quota = $options['default_quota'];
    if (array_key_exists('default_groups', $options))
      $this->groups = $options['default_groups'];
    if (array_key_exists('preferences', $options))
      $this->preferences = $options['preferences'];
    if (is_array($options['domain_config'])) {
      foreach ($options['domain_config'] AS $domain => $opts) {
        if (array_key_exists('bare-name', $opts) && $opts['bare-name']) {
          $this->uidMapping[$domain] = '/(.*)/';
          $this->mailMapping[$domain] = '$1';
        } elseif (array_key_exists('uid-prefix', $opts)) {
          $this->uidMapping[$domain] = "/" . $opts['uid-prefix'] . "(.*)/";
          $this->mailMapping[$domain] = $opts['uid-prefix'] . "$1";
        } elseif (array_key_exists('uid-suffix', $opts)) {
          $this->uidMapping[$domain] = "/(.*)" . $opts['uid-suffix'] . "/";
          $this->mailMapping[$domain] = "$1" . $opts['uid-suffix'];
        }
      }
    }
  }

  /**
   * Check if the password is correct without logging in the user
   *
   * @param string $uid The username
   * @param string $password The password
   *
   * @return true/false
   * @throws \OC\DatabaseException
   */
  public function checkPassword($uid, $password)
  {
    if (!class_exists("SoapClient")) {
      OCP\Util::writeLog('user_ispconfig', 'ERROR: PHP soap extension is not installed or not enabled', OCP\Util::ERROR);
      return false;
    }

    // get logins to check against the soap api
    $logins = $this->getPossibleLogins($uid);
    $authResult = false;

    $this->connectSoap();
    foreach ($logins AS $login) {
      list($uid, $mailbox, $domain) = $login;
      if ($domainUser = $this->tryDomainLogin($uid, $mailbox, $domain, $password)) {
        $authResult = $domainUser;
        break;
      }
    }
    $this->disconnectSoap();

    if ($authResult) {
      $quota = $this->getQuota($authResult['domain']);
      $groups = $this->getGroups($authResult['domain']);
      $preferences = $this->getParsedPreferences($authResult['uid'], $authResult["mailbox"], $authResult['domain']);
      $this->storeUser($authResult['uid'], $authResult["mailbox"], $authResult['domain'], $authResult['displayname'], $quota, $groups, $preferences);
      return $authResult['uid'];
    } else {
      return false;
    }
  }

  /**
   * Get possible UID, mailbox name and maildomain name combinations from users entered UID in Login Form
   *
   * @param string $uid
   * @return array array of multiple possible uid, mailbox and domain as strings
   * @throws \OC\DatabaseException
   */
  private function getPossibleLogins($uid)
  {
    // Get existing user from DB
    $returningUser = $this->getUserData($uid);
    if ($returningUser) {
      return array(array($returningUser['uid'], $returningUser['mailbox'], $returningUser['domain']));
    }

    // Make uid lower case
    $uid = strtolower($uid);
    $result = array();

    // Replace escaped @ symbol in uid (which is a mail address)
    // but only if there is no @ symbol and if there is a %40 inside the uid
    if (!(strpos($uid, '@') !== false) && (strpos($uid, '%40') !== false)) {
      $uid = str_replace("%40", "@", $uid);
    }
    list($mailbox, $domain) = array_pad(preg_split('/@/', $uid), 2, false);

    if ($domain) {
      // UID is an email address
      $result[] = $this->userFromEMail($mailbox, $domain);
    } else {
      // UID is no mail address
      $result = $this->usersFromUID($uid);
    }
    return $result;
  }

  /**
   * Get user account fields from mailbox and domain name
   *
   * Sets mailaddress as uid, if no mapping specified for this domain
   *
   * @param $mailbox
   * @param $domain
   * @return array [uid, mailbox, domain]
   */
  private function userFromEMail($mailbox, $domain)
  {
    if (array_key_exists($domain, $this->mailMapping)) {
      // re-map uid if options set for this domain
      $uid = preg_filter("/(.*)/", $this->mailMapping[$domain], $mailbox, 1);
      return array($uid, $mailbox, $domain);
    } else {
      // just take "as is" if not
      return array("$mailbox@$domain", $mailbox, $domain);
    }
  }

  /**
   * Get all possible user account fields for UID
   *
   * Maps uid back to mailbox and domain combinations
   *
   * @param $uid
   * @return array array of multiple [uid, mailbox, domain]
   */
  private function usersFromUID($uid)
  {
    // check for prefix, suffix or bare-name domains valid for this input
    $users = array();
    foreach ($this->uidMapping AS $mappingDomain => $pattern) {
      if ($mappedMailbox = preg_filter($pattern, '$1', $uid)) {
        $users[] = array($uid, $mappedMailbox, $mappingDomain);
      }
    }
    return $users;
  }

  /**
   * Authenticate user against ISPConfig mailuser api
   *
   * @param $uid
   * @param $mailbox
   * @param $domain
   * @param $password
   * @return array|bool false or domain user array (uid, mailbox, domain, displayname)
   */
  private function tryDomainLogin($uid, $mailbox, $domain, $password)
  {
    // Check, if domain is allowed
    if ($this->allowedDomains) {
      if (count($this->allowedDomains) && $domain && !in_array($domain, $this->allowedDomains)) {
        return false;
      }
    }
    $mailuser = $this->getMailuser($mailbox, $domain);
    if (count($mailuser)) {
      $displayname = $mailuser['name'];
      $cryptedPassword = $mailuser['password'];
      if (crypt($password, $cryptedPassword) === $cryptedPassword)
        return array("uid" => $uid, "mailbox" => $mailbox, "domain" => $domain, "displayname" => $displayname);
    }
    return false;
  }

  /**
   * Get domain specific or global quota for a specific domain
   *
   * @param string $domain the domain
   * @return bool|string false or quota definition
   */
  private function getQuota($domain)
  {
    if (array_key_exists('domain_config', $this->options) &&
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
  private function getGroups($domain)
  {
    if (array_key_exists('domain_config', $this->options) &&
        array_key_exists($domain, $this->options['domain_config']) &&
        array_key_exists('groups', $this->options['domain_config'][$domain]))
      return $this->options['domain_config'][$domain]['groups'];
    return $this->quota;
  }

  /**
   * Get parsed preferences to set for a new user
   *
   * Replaces placeholders %UID%, %MAILBOX% and %DOMAIN% in config values
   *
   * @param string $uid mapped UID
   * @param string $mailbox Mailbox name
   * @param string $domain Domain name
   * @return bool|array false or 2-dimensional string array (appid => [configkey => value] )
   */
  private function getParsedPreferences($uid, $mailbox, $domain)
  {
    $preferences = $this->getPreferencesFromConfig($domain);
    // Loop apps in preferences
    return array_map(
        function ($options) use ($uid, $mailbox, $domain) {
          return array_map(
              function ($value) use ($uid, $mailbox, $domain) {
                $pattern = array("/%UID%/", '/%MAILBOX%/', "/%DOMAIN%/");
                $replace = array($uid, $mailbox, $domain);
                return preg_replace($pattern, $replace, $value);
              }, $options);
        }, $preferences);
  }

  /**
   * Get preferences to set for other apps from config data
   *
   * @param string $domain the domain
   * @return bool|array false or 2-dimensional string array (appid => [configkey => value] )
   */
  private function getPreferencesFromConfig($domain)
  {
    if (array_key_exists('domain_config', $this->options) &&
        array_key_exists($domain, $this->options['domain_config']) &&
        array_key_exists('preferences', $this->options['domain_config'][$domain]))
      return $this->options['domain_config'][$domain]['preferences'];
    return $this->preferences;
  }

  /**
   * @param $uid UID in nextcloud
   * @param $password New Password
   * @return bool Successful updated?
   * @throws \OC\DatabaseException
   */
  public function setPassword($uid, $password)
  {
    $mailbox = '';
    $domain = '';
    extract($this->getUserData($uid));
    $this->connectSoap();
    $updateResult = $this->updateMailuser($mailbox, $domain, array("password" => $password));
    $this->disconnectSoap();
    return $updateResult;
  }

}
