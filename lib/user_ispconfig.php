<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

use \OCA\user_ispconfig\ISPDomainUser;

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
  /**
   * @var bool use UID mapping features, from authenticator config 'map_uids'
   */
  private $useUIDMapping = true;
  /**
   * @var bool|string[] Domain Whitelist for login from authenticator config
   */
  private $allowedDomains = false;
  /**
   * @var bool|string Default quota to set for new users (from authenticator config)
   */
  private $quota = false;
  /**
   * @var string[] Default groups to assign to new users (from authenticator config)
   */
  private $groups = array();
  /**
   * @var array Default preferences to set for new users for other apps (from authenticator config)
   */
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
   * @param string $location SOAP location of ISPConfig remote API
   * @param string $uri SOAP uri of ISPConfig remote API
   * @param string $user Remote user for ISPConfig remote API
   * @param string $password Remote users password
   * @param array $options Detailed config options from connector configuration in config.php
   */
  public function __construct($location, $uri, $user, $password, $options = array())
  {
    parent::__construct($location, $uri, $user, $password);
    $this->options = $options;
    if (array_key_exists('map_uids', $options))
      $this->useUIDMapping = !!$options['map_uids'];
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
   * Check if the password is correct on login
   *
   * Set some initial preference values on first login
   *
   * @param string $uid The username
   * @param string $password The password
   *
   * @return bool|string false/UID
   * @throws \OC\DatabaseException
   */
  public function checkPassword($uid, $password)
  {
    if (!class_exists("SoapClient")) {
      /** @noinspection PhpDeprecationInspection */
      OCP\Util::writeLog('user_ispconfig', 'ERROR: PHP soap extension is not installed or not enabled', OCP\Util::ERROR);
      return false;
    }
    if($this->useUIDMapping)
      $authResult = $this->loginWithUIDMapping($uid, $password);
    else
      $authResult = $this->loginWithUIDFromIspc($uid, $password);
    return $this->processAuthResult($authResult);
  }

  /**
   * Set new password for user UID
   *
   * @param string $uid UID in nextcloud
   * @param string $password New Password
   * @return bool Successful updated?
   * @throws \OC\DatabaseException
   */
  public function setPassword($uid, $password)
  {
    $this->connectSoap();
    if($this->useUIDMapping) {
      $user = $this->getUserData($uid);
      $updateResult = $this->updateMappedMailuser($user->getMailbox(), $user->getDomain(), array("password" => $password));
    } else {
      $updateResult = $this->updateIspcMailuser($uid, array("password" => $password));
    }
    $this->disconnectSoap();
    return $updateResult;
  }

  /**
   * Extract UID from authenticated user or return false
   *
   * Also set some initial preferences on login
   *
   * @param ISPDomainUser $authResult Domain User Info
   * @return bool|string false or UID
   * @throws \OC\DatabaseException
   */
  private function processAuthResult($authResult) {
    if ($authResult) {
      $quota = $this->getQuota($authResult);
      $groups = $this->getGroups($authResult);
      $preferences = $this->getParsedPreferences($authResult);
      $this->storeUser($authResult->getUid(), $authResult->getMailbox(), $authResult->getDomain(), $authResult->getDisplayname(), $quota, $groups, $preferences);
      return $authResult->getUid();
    } else {
      return false;
    }
  }

  /**
   * Try to login the user $uid by using UID mapping
   *
   * @param string $uid
   * @param string $password
   * @return ISPDomainUser|bool
   * @throws \OC\DatabaseException
   */
  private function loginWithUIDMapping($uid, $password) {
    // get logins to check against the soap api
    $logins = $this->getPossibleLogins($uid);
    $authResult = false;

    $this->connectSoap();
    foreach ($logins AS $login) {
      if ($domainUser = $this->tryDomainLoginWithMappedUID($login, $password)) {
        $authResult = $domainUser;
        break;
      }
    }
    $this->disconnectSoap();

    return $authResult;
  }

  /**
   * Try to login the user directly using the mailbox login name from ISPConfig
   *
   * @param string $uid
   * @param string $password
   * @return ISPDomainUser|bool Authenticated domain user or false
   */
  private function loginWithUIDFromIspc($uid, $password) {
    // get logins to check against the soap api
    $authResult = false;

    $this->connectSoap();
    if ($domainUser = $this->tryDomainLoginWithIspcUID($uid, $password)) {
      $authResult = $domainUser;
    }
    $this->disconnectSoap();

    return $authResult;
  }

  /**
   * Get possible UID, mailbox name and maildomain name combinations from users entered UID in Login Form
   * according to configured UID Mapping
   *
   * @param string $uid
   * @return ISPDomainUser[] array of possible domain users for login according to UID mapping
   * @throws \OC\DatabaseException
   */
  private function getPossibleLogins($uid)
  {
    // Get existing user from DB
    $returningUser = $this->getUserData($uid);
    if ($returningUser) {
      /** @noinspection PhpDeprecationInspection */
      // OCP\Util::writeLog('user_ispconfig', "Found returning user: $returningUser", OCP\Util::DEBUG);
      return array($returningUser);
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
   * @param string $mailbox
   * @param string $domain
   * @return ISPDomainUser One possible user for login
   */
  private function userFromEMail($mailbox, $domain)
  {
    if (array_key_exists($domain, $this->mailMapping)) {
      // re-map uid if options set for this domain
      $uid = preg_filter("/(.*)/", $this->mailMapping[$domain], $mailbox, 1);
      return new ISPDomainUser($uid, $mailbox, $domain);
    } else {
      // just take "as is" if not
      return new ISPDomainUser("$mailbox@$domain", $mailbox, $domain);
    }
  }

  /**
   * Get all possible user account fields for UID
   *
   * Maps uid back to mailbox and domain combinations
   *
   * @param string $uid
   * @return ISPDomainUser[] array of possible users for login according to UID mapping
   */
  private function usersFromUID($uid)
  {
    // check for prefix, suffix or bare-name domains valid for this input
    $users = array();
    foreach ($this->uidMapping AS $mappingDomain => $pattern) {
      if ($mappedMailbox = preg_filter($pattern, '$1', $uid)) {
        $users[] = new ISPDomainUser($uid, $mappedMailbox, $mappingDomain);
      }
    }
    return $users;
  }

  /**
   * Authenticate mapped user against ISPConfig mailuser api with mapped UID
   *
   * @param ISPDomainUser $user domainuser trying to login
   * @param string $password
   * @return ISPDomainUser|bool false or authenticated domain user
   */
  private function tryDomainLoginWithMappedUID($user, $password)
  {
    // Check, if domain is allowed
    if ($this->allowedDomains) {
      if (count($this->allowedDomains) && $user->getDomain() && !in_array($user->getDomain(), $this->allowedDomains)) {
        return false;
      }
    }
    $mailuser = $this->getMailuserByMailbox($user->getMailbox(), $user->getDomain());
    if (count($mailuser)) {
      $result = ISPDomainUser::fromMailuserIfPasswordMatch($user->getUid(), $password, $mailuser);
      /** @noinspection PhpDeprecationInspection */
      OCP\Util::writeLog('user_ispconfig', "Login result for $user: $result", OCP\Util::DEBUG);
      return $result;
    }
    return false;
  }

  /**
   * Authenticate mapped user against ISPConfig mailuser api with login name from ISPConfig
   *
   * @param string $uid
   * @param string $password
   * @return ISPDomainUser|bool false or authenticated domain user
   */
  private function tryDomainLoginWithIspcUID($uid, $password) {
    $mailuser = $this->getMailuserByLoginname($uid);

    if (count($mailuser)) {
      $domainuser = ISPDomainUser::fromMailuserIfPasswordMatch($uid, $password, $mailuser);
      if(!$domainuser)
        return false;
      // Check, if domain is allowed
      if ($this->allowedDomains) {
        if (count($this->allowedDomains) && $domainuser->getDomain() && !in_array($domainuser->getDomain(), $this->allowedDomains)) {
          return false;
        }
      }
      /** @noinspection PhpDeprecationInspection */
      OCP\Util::writeLog('user_ispconfig', "Login result for $uid: $domainuser", OCP\Util::DEBUG);
      return $domainuser;
    }
    return false;

  }

  /**
   * Get domain specific or global quota for a specific domain
   *
   * @param ISPDomainUser $user authenticated domainuser
   * @return bool|string false or quota definition
   */
  private function getQuota($user)
  {
    if (array_key_exists('domain_config', $this->options) &&
        array_key_exists($user->getDomain(), $this->options['domain_config']) &&
        array_key_exists('quota', $this->options['domain_config'][$user->getDomain()]))
      return $this->options['domain_config'][$user->getDomain()]['quota'];
    return $this->quota;
  }

  /**
   * Get domain specific or global initial groups for a specific domain
   *
   * @param ISPDomainUser $user authenticated domainuser
   * @return bool|array false or string array with group names
   */
  private function getGroups($user)
  {
    if (array_key_exists('domain_config', $this->options) &&
        array_key_exists($user->getDomain(), $this->options['domain_config']) &&
        array_key_exists('groups', $this->options['domain_config'][$user->getDomain()]))
      return $this->options['domain_config'][$user->getDomain()]['groups'];
    return $this->quota;
  }

  /**
   * Get parsed preferences to set for a new user
   *
   * Replaces placeholders %UID%, %MAILBOX% and %DOMAIN% in config values
   *
   * @param ISPDomainUser $user
   * @return bool|array false or 2-dimensional string array (appid => [configkey => value] )
   */
  private function getParsedPreferences($user)
  {
    $preferences = $this->getPreferencesFromConfig($user->getDomain());
    // Loop apps in preferences
    return array_map(
        function ($options) use ($user) {
          return array_map(
              function ($value) use ($user) {
                $pattern = array("/%UID%/", '/%MAILBOX%/', "/%DOMAIN%/");
                $replace = array($user->getUid(), $user->getMailbox(), $user->getDomain());
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


}
