<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\User_ISPConfig;

/**
 * User authentication against an ISPConfig 3 API server
 *
 * @category Apps
 * @package  UserISPConfig
 * @author   Michael Fürmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://github.com/SpicyWeb-de/nextcloud-user-ispconfig
 */

class ISPDomainUser {
  /**
   * @var string UID used in nextcloud
   */
  private $uid;
  /**
   * @var string mailbox name
   */
  private $mailbox;
  /**
   * @var string mail domain
   */
  private $domain;
  /**
   * @var string display name
   */
  private $displayname;

  /**
   * ISPDomainUser constructor.
   * @param string $uid
   * @param string $mailbox
   * @param string $domain
   * @param string $displayname
   */
  public function __construct($uid, $mailbox, $domain, $displayname = "")
  {
    $this->uid = $uid;
    $this->mailbox = $mailbox;
    $this->domain = $domain;
    $this->displayname = $displayname;
  }

  /**
   * @return string User String representation
   */
  public function __toString()
  {
    return $this->getDisplayname() . " <$this->mailbox@$this->domain>";
  }

  /**
   * Create a user object if passwords match or return false otherwise
   *
   * @param string $uid
   * @param string $password
   * @param array $mailuser Mailuser object from ISPC
   * @return bool|ISPDomainUser
   */
  public static function fromMailuserIfPasswordMatch($uid, $password, $mailuser) {
    if(self::checkPassword($password, $mailuser['password']))
      return self::fromMailuser($uid, $mailuser);
    return false;
  }

  /**
   * Create a mailuser object from ISPC API Data
   *
   * @param string $uid
   * @param array $mailuser ISPC Mailuser object
   * @return ISPDomainUser
   */
  public static function fromMailuser($uid, $mailuser) {
    $email = $mailuser['email'];
    list($mailbox, $domain) = array_pad(preg_split('/@/', $email), 2, false);
    $displayname = $mailuser['name'];
    return new ISPDomainUser($uid, $mailbox, $domain, $displayname);
  }

  /**
   * Check if entered password matches the crypted password in mailuser object
   *
   * @param $password
   * @param $cryptedPassword
   * @return bool
   */
  public static function checkPassword($password, $cryptedPassword) {
    return crypt($password, $cryptedPassword) === $cryptedPassword;
  }

  /**
   * @return string
   */
  public function getDisplayname()
  {
    return strlen($this->displayname) ? $this->displayname : $this->getUid();
  }

  /**
   * @return string
   */
  public function getDomain()
  {
    return $this->domain;
  }

  /**
   * @return string
   */
  public function getMailbox()
  {
    return $this->mailbox;
  }

  /**
   * @return string
   */
  public function getUid()
  {
    return $this->uid;
  }

}
