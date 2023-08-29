<?php
/**
 * Copyright (c) 2018 Michael FÃ¼rmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\user_ispconfig;

use \OC_DB;

/**
 * Base class for external auth implementations that stores users
 * on their first login in a local table.
 * This is required for making many of the user-related ownCloud functions
 * work, including sharing files with them.
 *
 * @category Apps
 * @package  UserISPConfig
 * @author   Christian Weiske <cweiske@cweiske.de>
 * @author   Michael FÃ¼rmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://github.com/SpicyWeb-de/nextcloud-user-ispconfig
 */
abstract class Base extends \OC\User\Backend
{

	/**
	 * Shortcut to get an instance of DB query builder
	 */
	private function query() {
		return \OC::$server->getDatabaseConnection()->getQueryBuilder();
	}

	/**
   * Delete a user
   *
   * @param string $uid The username of the user to delete
   * @return bool
   * @throws \OC\DatabaseException
   */
  public function deleteUser($uid)
  {
	  $query = $this->query();
    $query->delete('accounts')->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));
    $query->delete('preferences')->where($query->expr()->eq('userid', $query->createNamedParameter($uid)));
    $query->delete('group_user')->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));
    $query->delete('users_ispconfig')->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));
    $query->execute();
    return true;
  }

  /**
   * Get display name of the user
   *
   * @param string $uid user ID of the user
   * @return string display name or fallback to UID
   * @throws \OC\DatabaseException
   */
  public function getDisplayName($uid)
  {
	  $query = $this->query();
    $query->select('displayname')->from('users_ispconfig')->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));
	  $result = $query->execute();
	  $row = $result->fetch();
	  $result->closeCursor();

    $displayName = trim($row['displayname'], ' ');
    if (!empty($displayName)) {
      return $displayName;
    } else {
      return $uid;
    }
  }

  /**
   * Get data of returning user by uid or mailaddress
   *
   * @param string $loginName Login Name to check
   * @return ISPDomainUser|bool Found domainuser from database or false
   * @throws \OC\DatabaseException
   */
  public function getUserData($loginName)
  {
    $query = $this->query();
    list($mailbox, $domain) = array_pad(preg_split('/@/', $loginName), 2, false);
    $query->select('uid', 'mailbox', 'domain')->from('users_ispconfig')->where($query->expr()->eq('uid', $query->createNamedParameter($loginName)));
    if ($mailbox && $domain) {
		// TODO append or query matching mailbox AND domain
      $query->orWhere($query->expr()->eq('mailbox', $query->createNamedParameter($mailbox)));
      //$stmnt .= ' OR (`mailbox` = ? AND `domain` = ?)';
    }
	$result = $query->execute();
	$row = $result->fetch();
	$result->closeCursor();

    return $row ? new ISPDomainUser($row['uid'], $row['mailbox'], $row['domain']) : false;
  }

  /**
   * Get a list of all display names and user ids.
   *
   * @return array with all displayNames (value) and the corresponding uids (key)
   * @throws \OC\DatabaseException
   */
  public function getDisplayNames($search = '', $limit = null, $offset = null)
  {
    $query = $this->query();
	  // TODO how to : like lower %value%
	  // $query->select('uid', 'displayname')->from('users_ispconfig')->where($query->expr()->eq('uid', $query->createNamedParameter($loginName)));
    $result = OC_DB::executeAudited(
        array(
            'sql' => 'SELECT `uid`, `displayname` FROM `*PREFIX*users_ispconfig`'
                . ' WHERE (LOWER(`displayname`) LIKE LOWER(?) '
                . ' OR LOWER(`uid`) LIKE LOWER(?))',
            'limit' => $limit,
            'offset' => $offset
        ),
        array('%' . $search . '%', '%' . $search . '%')
    );

    $displayNames = array();
    while ($row = $result->fetchRow()) {
      $displayNames[$row['uid']] = $row['displayname'];
    }

    return $displayNames;
  }

  /**
   * Get a list of all users
   *
   * @return string[] with all uids
   * @throws \OC\DatabaseException
   */
  public function getUsers($search = '', $limit = null, $offset = null)
  {
	  // todo how to : like lower value%
	  // $query->select('uid')->from('users_ispconfig')->where($query->expr()->eq('uid', $query->createNamedParameter($search)));
    $result = OC_DB::executeAudited(
        array(
            'sql' => 'SELECT `uid` FROM `*PREFIX*users_ispconfig`'
                . ' WHERE LOWER(`uid`) LIKE LOWER(?)',
            'limit' => $limit,
            'offset' => $offset
        ),
        array($search . '%')
    );
    $users = array();
    while ($row = $result->fetchRow()) {
      $users[] = $row['uid'];
    }
    return $users;
  }

  /**
   * Determines if the backend can enlist users
   *
   * @return bool
   */
  public function hasUserListings()
  {
    return true;
  }

  /**
   * Change the display name of a user
   *
   * @param string $uid The username
   * @param string $displayName The new display name
   *
   * @return bool Update successful?
   * @throws \OC\DatabaseException
   */
  public function setDisplayName($uid, $displayName)
  {
    if (!$this->userExists($uid)) {
      return false;
    }
    // todo how to : = lower value
    OC_DB::executeAudited(
        'UPDATE `*PREFIX*users_ispconfig` SET `displayname` = ?'
        . ' WHERE LOWER(`uid`) = ?',
        array($displayName, $uid)
    );
    return true;
  }

  /**
   * Create user record in database
   *
   * @param string $uid The username
   * @param string $displayname Users displayname
   * @param string|bool $quota Amount of quota for new created user or false
   * @param string[]|bool $groups string-array of groups for new created user or false
   *
   * @return void
   * @throws \OC\DatabaseException
   */
  protected function storeUser($uid, $mailbox, $domain, $displayname, $quota = false, $groups = false, $preferences = false)
  {
    if (!$this->userExists($uid)) {
      //OC_DB::executeAudited(
      //    'INSERT INTO `*PREFIX*users_ispconfig` ( `uid`, `displayname`, `mailbox`, `domain` )'
      //   . ' VALUES( ?, ?, ?, ? )',
      //    array($uid, $displayname, $mailbox, $domain)
      //);

      $this->setInitialUserProfile($uid, "$mailbox@$domain", $displayname);
      if ($quota)
        $this->setUserQuota($uid, $quota);
      if ($groups)
        foreach ($groups AS $gid) {
          $this->addUserToGroup($uid, $gid);
        }
      if ($preferences)
        foreach ($preferences AS $app => $options)
          foreach ($options AS $configkey => $value)
            $this->setUserPreference($uid, $app, $configkey, $value);
    }
  }

  /**
   * Check if a user exists
   *
   * @param string $uid the username
   *
   * @return boolean
   * @throws \OC\DatabaseException
   */
  public function userExists($uid)
  {
    $query = $this->query();
    $query->select('displayname')->from('users_ispconfig')->where('uid = ?')->setParameter(0,$uid);
    $result = $query->execute();
    $row = $result->fetch();
    //$result->closeCursor();
    //return $result->fetchOne() > 0;
    return $row;
  }

  /**
   * @param string $uid the username
   * @param string $appid app to save the preference for
   * @param string $configkey config key to set
   * @param string $value value to save for the users preference
   * @throws \OC\DatabaseException
   */
  private function setUserPreference($uid, $appid, $configkey, $value)
  {
    $query = $this->query();
    $query->insert('preferences')->values(['userid' =>'?','appid' => '?', 'configkey' => '?', 'configvalue' => '?'])
			     ->setParameter(0,$uid)
			     ->setParameter(1,$appid)
			     ->setParameter(2,$configkey)
			     ->setParameter(3,$value);
    $result = $query->execute();
    return $result;

  }

  /**
   * @param string $uid the username
   * @param string $quota amount of quota
   * @throws \OC\DatabaseException
   */
  private function setUserQuota($uid, $quota)
  {
 
    $query = $this->query();
    $query->insert('preferences')->values(['userid' =>'?','appid' => '?', 'configkey' => '?', 'configvalue' => '?'])
                             ->setParameter(0,$uid)
                             ->setParameter(1,"files")
                             ->setParameter(2,"quota")
                             ->setParameter(3,$quota);
  $result = $query->execute();
    return $result;

  }

  /**
   * Add user to group
   *
   * @param string $uid the username
   * @param string $gid the groupname
   * @throws \OC\DatabaseException
   */
  protected function addUserToGroup($uid, $gid)
  {
    // Add group if not exists
//    $result = OC_DB::executeAudited(
//        'SELECT COUNT(*) FROM `*PREFIX*groups`'
//        . ' WHERE gid = ?',
//        array($gid)
//    );

    $query = $this->query();
    $query->select('gid')->from('groups')->where('gid = ?')->setParameter(0,$gid);
    $result = $query->execute();

    if($result->fetchOne() == 0){
      $query = $this->query();
      $query->insert('groups')->values(['gid' =>'?','displayname' => '?'])
                             ->setParameter(0,$gid)
                             ->setParameter(1,$gid);
      $result = $query->execute();
      return $result;

    }

    $query = $this->query();
    $query->insert('group_user')->values(['gid' =>'?','uid' => '?'])
                             ->setParameter(0,$gid)
                             ->setParameter(1,$uid);
    $result = $query->execute();
    return $result;
  }

  /**
   * Add user to group
   *
   * @param string $uid the username
   * @param string $email users mail address
   * @param string $displayname users real name
   * @throws \OC\DatabaseException
   */
  private function setInitialUserProfile($uid, $email, $displayname)
  {
    $this->setUserPreference($uid, 'settings', 'email', $email);
    OC_DB::executeAudited(
        'INSERT INTO `*PREFIX*accounts` ( `uid`, `data` )'
        . ' VALUES( ?, ? )',
        array($uid, json_encode(array(
            'displayname' => array(
                'value' => $displayname,
                'scope' => 'contacts',
                'verified' => 0
            ),
            'address' => array(
                'value' => '',
                'scope' => 'private',
                'verified' => 0
            ),
            'website' => array(
                'value' => '',
                'scope' => 'private',
                'verified' => 0
            ),
            'email' => array(
                'value' => $email,
                'scope' => 'contacts',
                'verified' => 0
            ),
            'avatar' => array(
                'scope' => 'contacts',
            ),
            'phone' => array(
                'value' => '',
                'scope' => 'private',
                'verified' => 0
            ),
            'twitter' => array(
                'value' => '',
                'scope' => 'private',
                'verified' => 0
            ),
        )))
    );
  }
}
