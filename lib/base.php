<?php
/**
 * Copyright (c) 2018 Michael Fürmann <michael@spicyweb.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\user_ispconfig;


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
 * @link     https://github.com/SpicyWeb-de/nextcloud-user-ispconfig
 */
abstract class Base extends \OC\User\Backend
{

	/**
	 * Shortcut to get an instance of DB connection
	 * @return \OCP\IDBConnection
	 */
	private function databaseConnection()
	{
		return \OC::$server->getDatabaseConnection();
		// (min NC25) return \OCP\Server::get(\OCP\IDBConnection::class);
	}

	/**
	 * Shortcut to get an instance of DB query builder
	 * @return \OCP\DB\QueryBuilder\IQueryBuilder
	 */
	private function query()
	{
		return $this->databaseConnection()->getQueryBuilder();
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

		$query
			->delete('accounts')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();

		$query
			->delete('preferences')
			->where($query->expr()->eq('userid', $query->createNamedParameter($uid)))
			->executeStatement();

		$query
			->delete('group_user')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();

		$query
			->delete('users_ispconfig')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();

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

		$result = $query
			->select('displayname')
			->from('users_ispconfig')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeQuery();

		$user = $result->fetch();
		$result->closeCursor();

		if (empty($user['displayname']))
			return $uid;

		return $user['displayname'];
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

		[$mailbox, $domain] = array_pad(preg_split('/@/', $loginName), 2, false);

		$query
			->select('uid', 'mailbox', 'domain')
			->from('users_ispconfig')
			->where($query->expr()->eq('uid', $query->createNamedParameter($loginName)));

		if ($mailbox && $domain)
		{
			$query->orWhere($query->expr()->andX(
				$query->expr()->eq('mailbox', $query->createNamedParameter($mailbox)),
				$query->expr()->eq('domain', $query->createNamedParameter($domain))
			));
		}

		$result = $query->executeQuery();
		$user = $result->fetch();
		$result->closeCursor();

		return $user ? new ISPDomainUser($user['uid'], $user['mailbox'], $user['domain']) : false;
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

		$query
			->select('uid', 'displayname')
			->from('users_ispconfig')
			->where($query->expr()->iLike('uid',
				$query->createNamedParameter('%' .  $this->databaseConnection()->escapeLikeParameter($search) . '%')))
			->orWhere($query->expr()->iLike('displayname',
				$query->createNamedParameter('%' .  $this->databaseConnection()->escapeLikeParameter($search) . '%')))
			;

		if ($limit)
			$query->setMaxResults($limit);

		if ($offset)
			$query->setFirstResult($offset);

		$result = $query->executeQuery();

		$displayNames = [];
		while ($row = $result->fetch())
		{
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
		$query = $this->query();

		$query
			->select('uid')
			->from('users_ispconfig')
			->where($query->expr()->iLike('uid',
				$query->createNamedParameter('%' .  $this->databaseConnection()->escapeLikeParameter($search) . '%')))
			;

		if ($limit)
			$query->setMaxResults($limit);

		if ($offset)
			$query->setFirstResult($offset);

		$result = $query->executeQuery();

		$users = [];
		while ($row = $result->fetch())
		{
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
		if (!$this->userExists($uid))
			return false;

		$query = $this->query();

		$query
			->update('users_ispconfig')
			->set('displayname', $query->createNamedParameter($displayName))
			->where($query->expr()->iLike('uid', $query->createNamedParameter($uid)))
			->executeStatement();

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
	protected function storeUser($uid, $mailbox, $domain, $displayname, $quota = false, $groups = false,
		$preferences = false)
	{
		if ($this->userExists($uid))
			return;

		$query = $this->query();

		$query
			->insert('users_ispconfig')
			->values([
				'uid' => $query->createNamedParameter($uid),
				'displayname' => $query->createNamedParameter($displayname),
				'mailbox' => $query->createNamedParameter($mailbox),
				'domain' => $query->createNamedParameter($domain),
			])
			->executeStatement();

		$this->setInitialUserProfile($uid, "$mailbox@$domain", $displayname);

		if ($quota)
			$this->setUserPreference($uid, 'files', 'quota', $quota);

		if ($groups)
		{
			foreach ($groups AS $gid)
			{
				$this->addUserToGroup($uid, $gid);
			}
		}

		if ($preferences)
		{
			foreach ($preferences AS $app => $options)
			{
				foreach ($options AS $configkey => $value)
				{
					$this->setUserPreference($uid, $app, $configkey, $value);
				}
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
	public function userExists($uid)
	{
		$query = $this->query();

		$result = $query
			->select($query->func()->count('*'))
			->from('users_ispconfig')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeQuery();

		$users = $result->fetchOne();
		$result->closeCursor();

		return $users > 0;
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

		$query
			->insert('preferences')
			->values([
				'userid' => $query->createNamedParameter($uid),
				'appid' => $query->createNamedParameter($appid),
				'configkey' => $query->createNamedParameter($configkey),
				'configvalue' => $query->createNamedParameter($value),
			])
			->executeStatement();
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
		$query = $this->query();

		$result = $query
			->select($query->func()->count('*'))
			->from('groups')
			->where($query->expr()->eq('gid', $query->createNamedParameter($gid)))
			->executeQuery();

		if ($result->fetchOne() == 0)
		{
			$query
				->insert('groups')
				->values([
					'gid' => $query->createNamedParameter($gid),
					'displayname' => $query->createNamedParameter($gid),
				])
				->executeStatement();
		}
		$result->closeCursor();

		$query
			->insert('group_user')
			->values([
				'gid' => $query->createNamedParameter($gid),
				'uid' => $query->createNamedParameter($uid),
			])
			->executeStatement();
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

		$query = $this->query();

		$query
			->insert('accounts')
			->values([
				'uid' => $query->createNamedParameter($uid),
				'data' => $query->createNamedParameter(json_encode([
					'displayname' => [
						'value' => $displayname,
						'scope' => 'contacts',
						'verified' => 0
					],
					'address' => [
						'value' => '',
						'scope' => 'private',
						'verified' => 0
					],
					'website' => [
						'value' => '',
						'scope' => 'private',
						'verified' => 0
					],
					'email' => [
						'value' => $email,
						'scope' => 'contacts',
						'verified' => 0
					],
					'avatar' => [
						'scope' => 'contacts',
					],
					'phone' => [
						'value' => '',
						'scope' => 'private',
						'verified' => 0
					],
					'twitter' => [
						'value' => '',
						'scope' => 'private',
						'verified' => 0
					],
				])),
			])
			->executeStatement();
	}
}
