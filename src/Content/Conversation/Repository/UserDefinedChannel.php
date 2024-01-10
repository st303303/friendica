<?php
/**
 * @copyright Copyright (C) 2010-2024, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Content\Conversation\Repository;

use Friendica\BaseCollection;
use Friendica\Content\Conversation\Collection\UserDefinedChannels;
use Friendica\Content\Conversation\Entity;
use Friendica\Content\Conversation\Factory;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Post\Engagement;
use Friendica\Model\User;
use Friendica\Util\DateTimeFormat;
use Psr\Log\LoggerInterface;

class UserDefinedChannel extends \Friendica\BaseRepository
{
	protected static $table_name = 'channel';

	/** @var IManageConfigValues */
	private $config;

	public function __construct(Database $database, LoggerInterface $logger, Factory\UserDefinedChannel $factory, IManageConfigValues $config)
	{
		parent::__construct($database, $logger, $factory);

		$this->config = $config;
	}

	/**
	 * @param array $condition
	 * @param array $params
	 * @return UserDefinedChannels
	 * @throws \Exception
	 */
	protected function _select(array $condition, array $params = []): BaseCollection
	{
		$rows = $this->db->selectToArray(static::$table_name, [], $condition, $params);

		$Entities = new UserDefinedChannels();
		foreach ($rows as $fields) {
			$Entities[] = $this->factory->createFromTableRow($fields);
		}

		return $Entities;
	}

	public function select(array $condition, array $params = []): UserDefinedChannels
	{
		return $this->_select($condition, $params);
	}

	/**
	 * Fetch a single user channel
	 *
	 * @param int $id  The id of the user defined channel
	 * @param int $uid The user that this channel belongs to. (Not part of the primary key)
	 * @return Entity\UserDefinedChannel
	 * @throws \Friendica\Network\HTTPException\NotFoundException
	 */
	public function selectById(int $id, int $uid): Entity\UserDefinedChannel
	{
		return $this->_selectOne(['id' => $id, 'uid' => $uid]);
	}

	/**
	 * Checks if the provided channel id exists for this user
	 *
	 * @param integer $id
	 * @param integer $uid
	 * @return boolean
	 */
	public function existsById(int $id, int $uid): bool
	{
		return $this->exists(['id' => $id, 'uid' => $uid]);
	}

	/**
	 * Delete the given channel
	 *
	 * @param integer $id
	 * @param integer $uid
	 * @return boolean
	 */
	public function deleteById(int $id, int $uid): bool
	{
		return $this->db->delete(self::$table_name, ['id' => $id, 'uid' => $uid]);
	}

	/**
	 * Fetch all user channels
	 *
	 * @param integer $uid
	 * @return UserDefinedChannels
	 * @throws \Exception
	 */
	public function selectByUid(int $uid): UserDefinedChannels
	{
		return $this->_select(['uid' => $uid]);
	}

	public function save(Entity\UserDefinedChannel $Channel): Entity\UserDefinedChannel
	{
		$fields = [
			'label'            => $Channel->label,
			'description'      => $Channel->description,
			'access-key'       => $Channel->accessKey,
			'uid'              => $Channel->uid,
			'circle'           => $Channel->circle,
			'include-tags'     => $Channel->includeTags,
			'exclude-tags'     => $Channel->excludeTags,
			'full-text-search' => $Channel->fullTextSearch,
			'media-type'       => $Channel->mediaType,
			'languages'        => serialize($Channel->languages),
			'publish'          => $Channel->publish,
		];

		if ($Channel->code) {
			$this->db->update(self::$table_name, $fields, ['uid' => $Channel->uid, 'id' => $Channel->code]);
		} else {
			$this->db->insert(self::$table_name, $fields, Database::INSERT_IGNORE);

			$newChannelId = $this->db->lastInsertId();

			$Channel = $this->selectById($newChannelId, $Channel->uid);
		}

		return $Channel;
	}

	/**
	 * Checks, if one of the user defined channels matches with the given search text
	 * @todo Combine all the full text statements in a single search text to improve the performance.
	 * Add a "valid" field for the channel that is set when the full text statement doesn't contain errors.
	 *
	 * @param string $searchtext
	 * @param string $language
	 * @param array  $tags
	 * @param int    $media_type
	 * @return boolean
	 */
	public function match(string $searchtext, string $language, array $tags, int $media_type): bool
	{
		$users = $this->db->selectToArray('user', ['uid'], $this->getUserCondition());
		if (empty($users)) {
			return [];
		}

		$uids = array_column($users, 'uid');

		$condition = ['uid' => $uids];
		$condition = DBA::mergeConditions($condition, ["`languages` != ? AND `include-tags` = ? AND `full-text-search` = ? AND circle = ?", '', '', '', 0]);

		foreach ($this->select($condition) as $channel) {
			if (!empty($channel->languages) && in_array($language, $channel->languages)) {
				return true;
			}
		}

		return !empty($this->getMatches($searchtext, $language, $tags, $media_type, 0, 0, $uids, false));
	}

	/**
	 * Fetch the channel users that have got matching channels
	 *
	 * @param string $searchtext
	 * @param string $language
	 * @param array  $tags
	 * @param int    $media_type
	 * @param int    $owner_id
	 * @param int    $reshare_id
	 * @return array
	 */
	public function getMatchingChannelUsers(string $searchtext, string $language, array $tags, int $media_type, int $owner_id, int $reshare_id): array
	{
		$condition = $this->getUserCondition();
		$condition = DBA::mergeConditions($condition, ["`account-type` IN (?, ?) AND `uid` != ?", User::ACCOUNT_TYPE_RELAY, User::ACCOUNT_TYPE_COMMUNITY, 0]);
		$users = $this->db->selectToArray('user', ['uid'], $condition);
		if (empty($users)) {
			return [];
		}
		return $this->getMatches($searchtext, $language, $tags, $media_type, $owner_id, $reshare_id, array_column($users, 'uid'), true);
	}

	private function getMatches(string $searchtext, string $language, array $tags, int $media_type, int $owner_id, int $reshare_id, array $channelUids, bool $relayMode): array
	{
		if (!in_array($language, User::getLanguages())) {
			$this->logger->debug('Unwanted language found. No matched channel found.', ['language' => $language, 'searchtext' => $searchtext]);
			return [];
		}

		$this->db->insert('check-full-text-search', ['pid' => getmypid(), 'searchtext' => $searchtext], Database::INSERT_UPDATE);

		$uids = [];

		$condition = ['uid' => $channelUids];
		if (!$relayMode) {
			$condition = DBA::mergeConditions($condition, ["`full-text-search` != ?", '']);
		} else {
			$condition = DBA::mergeConditions($condition, ['publish' => true]);
		}

		foreach ($this->select($condition) as $channel) {
			if (in_array($channel->uid, $uids)) {
				continue;
			}
			if (!empty($channel->circle) && ($channel->circle > 0) && !in_array($channel->uid, $uids)) {
				if (!$this->inCircle($channel->circle, $channel->uid, $owner_id) && !$this->inCircle($channel->circle, $channel->uid, $reshare_id)) {
					continue;
				}
			}
			if (!empty($channel->languages) && !in_array($channel->uid, $uids)) {
				if (!in_array($language, $channel->languages)) {
					continue;
				}
			} elseif (!in_array($language, User::getWantedLanguages($channel->uid))) {
				continue;
			}
			if (!empty($channel->includeTags) && !in_array($channel->uid, $uids)) {
				if (!$this->inTaglist($channel->includeTags, $tags)) {
					continue;
				}
			}
			if (!empty($channel->excludeTags) && !in_array($channel->uid, $uids)) {
				if ($this->inTaglist($channel->excludeTags, $tags)) {
					continue;
				}
			}
			if (!empty($channel->mediaType) && !in_array($channel->uid, $uids)) {
				if (!($channel->mediaType & $media_type)) {
					continue;
				}
			}
			if (!empty($channel->fullTextSearch) && !in_array($channel->uid, $uids)) {
				if (!$this->inFulltext($channel->fullTextSearch)) {
					continue;
				}
			}
			$uids[] = $channel->uid;
			$this->logger->debug('Matching channel found.', ['uid' => $channel->uid, 'label' => $channel->label, 'language' => $language, 'tags' => $tags, 'media_type' => $media_type, 'searchtext' => $searchtext]);
			if (!$relayMode) {
				return $uids;
			}
		}

		$this->db->delete('check-full-text-search', ['pid' => getmypid()]);
		return $uids;
	}

	private function inCircle(int $circleId, int $uid, int $cid): bool
	{
		if ($cid == 0) {
			return false;
		}

		$account = Contact::selectFirstAccountUser(['id'], ['pid' => $cid, 'uid' => $uid]);
		if (empty($account['id'])) {
			return false;
		}
		return $this->db->exists('group_member', ['gid' => $circleId, 'contact-id' => $account['id']]);
	}

	private function inTaglist(string $tagList, array $tags): bool
	{
		if (empty($tags)) {
			return false;
		}
		array_walk($tags, function (&$value) {
			$value = mb_strtolower($value);
		});
		foreach (explode(',', $tagList) as $tag) {
			if (in_array($tag, $tags)) {
				return true;
			}
		}
		return false;
	}

	private function inFulltext(string $fullTextSearch): bool
	{
		foreach (Engagement::KEYWORDS as $keyword) {
			$fullTextSearch = preg_replace('~(' . $keyword . ':.[\w@\.-]+)~', '"$1"', $fullTextSearch);
		}
		return $this->db->exists('check-full-text-search', ["`pid` = ? AND MATCH (`searchtext`) AGAINST (? IN BOOLEAN MODE)", getmypid(), $fullTextSearch]);
	}

	private function getUserCondition()
	{
		$condition = ["`verified` AND NOT `blocked` AND NOT `account_removed` AND NOT `account_expired` AND `user`.`uid` > ?", 0];

		$abandon_days = intval($this->config->get('system', 'account_abandon_days'));
		if (!empty($abandon_days)) {
			$condition = DBA::mergeConditions($condition, ["`last-activity` > ?", DateTimeFormat::utc('now - ' . $abandon_days . ' days')]);
		}
		return $condition;
	}
}
