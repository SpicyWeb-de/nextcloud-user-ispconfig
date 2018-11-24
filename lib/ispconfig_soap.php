<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\user_ispconfig;

use OCP\Util;
use SoapClient;
use SoapFault;

/**
 * ISPConfig Soap layer class for mailuser authentictaion
 *
 * @category Apps
 * @package  UserISPConfig
 * @author   Michael Fürmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://spicyhub.de/spicy-web/nextcloud-user-ispconfig
 */
abstract class ISPConfig_SOAP extends \OCA\user_ispconfig\Base
{
  /**
   * @var SoapClient Soap Connection to ISPConfig remote api
   */
  private $soap = null;
  /**
   * @var string|boolean false or Session ID of established Soap Connection
   */
  private $session;

  private $location;
  private $uri;
  private $remoteUser;
  private $remotePassword;

  /**
   * ISPConfig_SOAP constructor.
   * @param $location ISPConfig API location
   * @param $uri ISPConfig API uri
   * @param $user ISPConfig remote user
   * @param $password ISPConfig remote user password
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
    $this->soap = new SoapClient(null, array('location' => $this->location, 'uri' => $this->uri));
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
   * Get data array of an ISPConfig managed mailuser
   *
   * @param $mailbox Mailbox name
   * @param $domain Domain name
   * @return bool|array false or Data Array containing all of the mailusers data fields as specified by ISPConfig mailuser api
   */
  protected function getMailuser($mailbox, $domain)
  {
    try {
      if ($this->session) {
        $mailuser = $this->soap->mail_user_get($this->session, array('email' => "$mailbox@$domain"));
        if (count($mailuser)) {
          return $mailuser[0];
        }
      } else {
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', Util::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
  }

  /**
   * Update data fields of an ISPConfig managed mailuser in ISPConfig
   *
   * @param $mailbox Mailbox name
   * @param $domain Domain name
   * @param $newParams Array containing Data Fields to change, refer to ISPConfig API documentation for information on available fields
   * @return bool Update successful?
   */
  protected function updateMailuser($mailbox, $domain, $newParams)
  {
    try {
      if ($this->session) {
        Util::writeLog('user_ispconfig', "New Password for $mailbox | $domain", Util::ERROR);
        $mailuser = $this->getMailuser($mailbox, $domain);
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
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
    }
    return false;
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
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', Util::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
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
        Util::writeLog('user_ispconfig', "Remote Client ID: $clientid", Util::ERROR);
        return $clientid;
      } else {
        Util::writeLog('user_ispconfig', 'SOAP error: SOAP session not established', Util::ERROR);
      }
    } catch (SoapFault $e) {
      $this->handleSOAPFault($e);
      return false;
    }
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
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid location or uri of ISPConfig SOAP Endpoint', Util::ERROR);
        break;
      case 'The login failed. Username or password wrong.':
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Invalid credentials of ISPConfig remote user', Util::ERROR);
        break;
      case 'You do not have the permissions to access this function.':
        Util::writeLog('user_ispconfig', 'SOAP Request failed: Ensure ISPConfig remote user has the following permissions: Customer Functions, Server Functions, E-Mail User Functions', Util::ERROR);
        break;
      default:
        Util::writeLog('user_ispconfig', 'SOAP Request failed: [' . $error->getCode() . '] ' . $error->getMessage(), Util::ERROR);
        break;
    }
  }
}
