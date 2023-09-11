<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\User_ISPConfig;

use OCP\Util;
use OCP\ILogger;
use SoapClient;
use SoapFault;

/**
 * ISPConfig Soap layer class for mailuser authentictaion
 *
 * @category Apps
 * @package  UserISPConfig
 * @author   Michael Fürmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://github.com/SpicyWeb-de/nextcloud-user-ispconfig
 */
abstract class ISPConfig_SOAP extends Base
{
  /**
   * @var SoapClient Soap Connection to ISPConfig remote api
   */
  private $soap = null;
  /**
   * @var string|boolean false or Session ID of established Soap Connection
   */
  private $session;
  /**
   * @var string ISPC Remote API location from authenticatior config options
   */
  private $location;
  /**
   * @var string ISPC Remote API URI from authenticatior config options
   */
  private $uri;
  /**
   * @var string ISPC Remote user from authenticatior config options
   */
  private $remoteUser;
  /**
   * @var string ISPC Remote password from authenticatior config options
   */
  private $remotePassword;

  /**
   * ISPConfig_SOAP constructor.
   * @param string $location ISPConfig API location
   * @param string $uri ISPConfig API uri
   * @param string $user ISPConfig remote user
   * @param string $password ISPConfig remote user password
   */
  public function __construct($location, $uri, $user, $password)
  {
    $this->location = $location;
    $this->uri = $uri;
    $this->remoteUser = $user;
    $this->remotePassword = $password;
  }

  /**
   * Connect to the soap api
   *
   * Call this before calling any other methods of this soap layer class
   */
  protected function connectSoap()
  {
    //Util::writeLog('user_ispconfig', "$this->location $this->uri", Util::ERROR);
    $this->soap = new SoapClient(null,
        array('location' => $this->location,
              'uri' => $this->uri,
              'ssl_method' => SOAP_SSL_METHOD_TLS,
              'trace' => 1,
              'stream_context' => stream_context_create(array( 'ssl' => array('verify_peer' => false,
                                                                              'verify_peer_name' => false,
                                                                              'allow_self_signed' => true //can fiddle with this one.
              )
    ))));
    $this->session = $this->soap->login($this->remoteUser, $this->remotePassword);
  }

  /**
   * Disconnect from soap api
   *
   * Don't forget to call this after your codes work is done to close the connection and free
   * resources on both ISPConfig Panel and nextcloud server
   */
  protected function disconnectSoap()
  {
    $this->soap->logout($this->session);
    $this->session = false;
  }

  /**
   * Get data array of an ISPConfig managed mailuser authenticated by mailbox and domain
   *
   * @param string $mailbox Mailbox name
   * @param string $domain Domain name
   * @return bool|array false or Data Array containing all of the mailusers data fields as specified by ISPConfig mailuser api
   */
  protected function getMailuserByMailbox($mailbox, $domain)
  {
    try {
      if ($this->session) {
        $mailuser = $this->soap->mail_user_get($this->session, array('email' => "$mailbox@$domain"));
        if (count($mailuser)) {
          return $mailuser[0];
        }
      } else {
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', ILogger::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
    return false;
  }

  /**
   * Get data array of an ISPConfig managed mailuser authenticated by ISPConfig Mail Login Name
   *
   * @param string $uid Loginname from ISPConfig
   * @return bool|array false or Data Array containing all of the mailusers data fields as specified by ISPConfig mailuser api
   */
  protected function getMailuserByLoginname($uid)
  {
    try {
      if ($this->session) {
        $mailuser = $this->soap->mail_user_get($this->session, array('login' => $uid));
        if (count($mailuser)) {
          return $mailuser[0];
        }
      } else {
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', ILogger::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
    return false;
  }

  /**
   * Update data fields of an ISPConfig managed mailuser identified by mailbox and domain in ISPConfig
   *
   * @param string $mailbox Mailbox name
   * @param string $domain Domain name
   * @param array $newParams Array containing Data Fields to change, refer to ISPConfig API documentation for information on available fields
   * @return bool Update successful?
   */
  protected function updateMappedMailuser($mailbox, $domain, $newParams)
  {
    try {
      if ($this->session) {
        Util::writeLog('user_ispconfig', "New Password for $mailbox | $domain", ILogger::DEBUG);
        $mailuser = $this->getMailuserByMailbox($mailbox, $domain);
        return $this->updateMailuser($mailuser, $newParams);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
    }
    return false;
  }

  /**
   * Update data fields of an ISPConfig managed mailuser identified by ISPConfig Mail Login in ISPConfig
   *
   * @param string $uid Loginname from ISPConfig
   * @param array $newParams Array containing Data Fields to change, refer to ISPConfig API documentation for information on available fields
   * @return bool Update successful?
   */
  protected function updateIspcMailuser($uid, $newParams)
  {
    try {
      if ($this->session) {
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', "New Password for $uid", ILogger::DEBUG);
        $mailuser = $this->getMailuserByLoginname($uid);
        return $this->updateMailuser($mailuser, $newParams);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
    }
    return false;
  }

  /**
   * Update a mailuser object with new parameters and send it to ISPConfig Mailuser API
   *
   * @param array $mailuser
   * @param array $newParams
   * @return bool Update successful?
   */
  private function updateMailuser($mailuser, $newParams) {
    if (version_compare($this->getBackendVersion()['ispc_app_version'], '3.1dev', '<')) {
      $startdate = array('year' => substr($mailuser['autoresponder_start_date'], 0, 4),
          'month' => substr($mailuser['autoresponder_start_date'], 5, 2),
          'day' => substr($mailuser['autoresponder_start_date'], 8, 2),
          'hour' => substr($mailuser['autoresponder_start_date'], 11, 2),
          'minute' => substr($mailuser['autoresponder_start_date'], 14, 2));
      $enddate = array('year' => substr($mailuser['autoresponder_end_date'], 0, 4),
          'month' => substr($mailuser['autoresponder_end_date'], 5, 2),
          'day' => substr($mailuser['autoresponder_end_date'], 8, 2),
          'hour' => substr($mailuser['autoresponder_end_date'], 11, 2),
          'minute' => substr($mailuser['autoresponder_end_date'], 14, 2));
      $mailuser['autoresponder_end_date'] = $enddate;
      $mailuser['autoresponder_start_date'] = $startdate;
    }
    $params = array_merge($mailuser, $newParams);
    $remoteUid = $this->getBackendClientID($mailuser['sys_userid']);
    $rowsUpdated = $this->soap->mail_user_update($this->session, $remoteUid, $mailuser['mailuser_id'], $params);
    return !!$rowsUpdated;
  }

  /**
   * Get version information fields for the ISPConfig Panel server installation
   *
   * @return bool|array false or array containing version information
   */
  private function getBackendVersion()
  {
    try {
      if ($this->session) {
        return $this->soap->server_get_app_version($this->session);
      } else {
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', ILogger::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
    return false;
  }

  /**
   * Get client id of ISPConfig user
   *
   * @param $userId
   * @return bool|integer false or client id
   */
  private function getBackendClientID($userId)
  {
    try {
      if ($this->session) {
        $clientid = $this->soap->client_get_id($this->session, $userId);
        return $clientid;
      } else {
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', ILogger::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
    return false;
  }

  /**
   * Log Nextcloud Error Messages for SOAPFault errors
   *
   * @param SOAPFault $error Error object
   */
  private function handleSOAPFault($error)
  {
    $errorMsg = $error->getMessage();
    switch ($errorMsg) {
      case 'looks like we got no XML document':
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid location or uri of ISPConfig SOAP Endpoint', ILogger::ERROR);
        break;
      case 'The login failed. Username or password wrong.':
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid credentials of ISPConfig remote user', ILogger::ERROR);
        break;
      case 'You do not have the permissions to access this function.':
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Ensure ISPConfig remote user has the following permissions: Customer Functions, Server Functions, E-Mail User Functions', ILogger::ERROR);
        break;
      default:
        /** @noinspection PhpDeprecationInspection */
        Util::writeLog('user_ispconfig', 'SOAP Request failed: [' . $error->getCode() . '] ' . $error->getMessage(), ILogger::ERROR);
        break;
    }
  }
}
