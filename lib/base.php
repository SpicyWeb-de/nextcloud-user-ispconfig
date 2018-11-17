<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
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
 * @author   Michael Fürmann <michael@spicyweb.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     https://spicyhub.de/spicy-web/nextcloud-user-ispconfig
 */
abstract class Base extends \OC\User\Backend{

    /**
     * Delete a user
     *
     * @param string $uid The username of the user to delete
     * @return bool
     * @throws \OC\DatabaseException
     */
	public function deleteUser($uid) {
        OC_DB::executeAudited(
            'DELETE FROM `*PREFIX*accounts` WHERE `uid` = ?',
            array($uid)
        );
        OC_DB::executeAudited(
            'DELETE FROM `*PREFIX*preferences` WHERE `userid` = ?',
            array($uid)
        );
        OC_DB::executeAudited(
            'DELETE FROM `*PREFIX*group_user` WHERE `uid` = ?',
            array($uid)
        );
        OC_DB::executeAudited(
            'DELETE FROM `*PREFIX*users_ispconfig` WHERE `uid` = ?',
            array($uid)
        );
		return true;
	}

	/**
	 * Get display name of the user
     *
	 * @param string $uid user ID of the user
	 * @return string display name
     * @throws \OC\DatabaseException
	 */
	public function getDisplayName($uid) {
		$user = OC_DB::executeAudited(
			'SELECT `displayname` FROM `*PREFIX*users_ispconfig`'
			. ' WHERE `uid` = ?',
			array($uid)
		)->fetchRow();
		$displayName = trim($user['displayname'], ' ');
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
     * @return array uid, mailbox, domain
     * @throws \OC\DatabaseException
     */
    public function getUserData($loginName) {
        $user = OC_DB::executeAudited(
            'SELECT `uid`, `mailbox`, `domain` FROM `*PREFIX*users_ispconfig`'
            . ' WHERE `uid` = ? OR CONCAT(`mailbox`, "@", `domain`) = ?',
            array($loginName, $loginName)
        )->fetchRow();
        return $user;
    }

	/**
	 * Get a list of all display names and user ids.
	 *
	 * @return array with all displayNames (value) and the corresponding uids (key)
     * @throws \OC\DatabaseException
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		$result = OC_DB::executeAudited(
			array(
				'sql' => 'SELECT `uid`, `displayname` FROM `*PREFIX*users_ispconfig`'
					. ' WHERE (LOWER(`displayname`) LIKE LOWER(?) '
					. ' OR LOWER(`uid`) LIKE LOWER(?))',
				'limit'  => $limit,
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
	* @return array with all uids
    * @throws \OC\DatabaseException
	*/
	public function getUsers($search = '', $limit = null, $offset = null) {
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
	public function hasUserListings() {
		return true;
	}

	/**
	 * Change the display name of a user
	 *
	 * @param string $uid         The username
	 * @param string $displayName The new display name
	 *
	 * @return true/false
     * @throws \OC\DatabaseException
	 */
	public function setDisplayName($uid, $displayName) {
		if (!$this->userExists($uid)) {
			return false;
		}
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
     * @param array|bool $groups string-array of groups for new created user or false
	 *
	 * @return void
     * @throws \OC\DatabaseException
	 */
	protected function storeUser($uid, $mailbox, $domain, $displayname, $quota = false, $groups = false)
	{
		if (!$this->userExists($uid)) {
			OC_DB::executeAudited(
				'INSERT INTO `*PREFIX*users_ispconfig` ( `uid`, `displayname`, `mailbox`, `domain` )'
				. ' VALUES( ?, ?, ?, ? )',
				array($uid, $displayname, $mailbox, $domain)
			);

            $this->setInitialUserProfile($uid, "$mailbox@$domain", $displayname);
			if ($quota)
			    $this->setUserQuota($uid, $quota);
			if($groups)
                foreach($groups AS $gid) {
                    $this->addUserToGroup($uid, $gid);
                }
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
	public function userExists($uid) {
		$result = OC_DB::executeAudited(
			'SELECT COUNT(*) FROM `*PREFIX*users_ispconfig`'
			. ' WHERE LOWER(`uid`) = LOWER(?)',
			array($uid)
		);
		return $result->fetchOne() > 0;
	}

    /**
     * @param string $uid the username
     * @param string $quota amount of quota
     * @throws \OC\DatabaseException
     */
	private function setUserQuota($uid, $quota) {
        OC_DB::executeAudited('INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, `configkey`, `configvalue`)'
            . ' VALUES (?, ?, ?, ?)',
            array($uid, 'files', 'quota', $quota)
        );
    }

    /**
     * Add user to group
     *
     * @param string $uid the username
     * @param string $gid the groupname
     * @throws \OC\DatabaseException
     */
    protected function addUserToGroup($uid, $gid){
        OC_DB::executeAudited(
            'INSERT INTO `*PREFIX*group_user` (`gid`, `uid`) VALUES (?, ?)',
            array($gid, $uid)
        );
    }

    /**
     * Add user to group
     *
     * @param string $uid the username
     * @param string $email users mail address
     * @param string $displayname users real name
     * @throws \OC\DatabaseException
     */
    private function setInitialUserProfile($uid, $email, $displayname) {
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
