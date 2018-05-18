<?php

namespace Wallee\Entity;

/**
 * Pseudo-Entity.
 * Provides static methods to interact with cron jobs.
 */
class Cron extends AbstractEntity {
	const STATE_PENDING = 'pending';
	const STATE_PROCESSING = 'processing';
	const STATE_SUCCESS = 'success';
	const STATE_ERROR = 'error';
	const CONSTRAINT_PENDING = 0;
	const CONSTRAINT_PROCESSING = -1;
	const MAX_RUN_TIME_MINUTES = 10;
	const TIMEOUT_MINUTES = 5;

	protected static function getBaseFields(){
		return array(
			'id' => ResourceType::INTEGER 
		);
	}

	protected static function getTableName(){
		return 'wallee_cron';
	}

	protected static function getFieldDefinition(){
		return array(
			'security_token' => ResourceType::STRING,
			'state' => ResourceType::STRING,
			'constraint_key' => ResourceType::INTEGER,
			'date_scheduled' => ResourceType::DATETIME,
			'date_started' => ResourceType::DATETIME,
			'date_completed' => ResourceType::DATETIME,
			'error_message' => ResourceType::STRING 
		);
	}

	public static function setProcessing(\Registry $registry, $security_token){
		$db = $registry->get('db');
		$table = DB_PREFIX . self::getTableName();
		$constraint = self::CONSTRAINT_PROCESSING;
		$processing = self::STATE_PROCESSING;
		$pending = self::STATE_PENDING;
		$security_token = $db->escape($security_token);
		$time = new \DateTime();
		$time = $time->format('Y-m-d H:i:s');
		
		$query = "UPDATE $table SET constraint_key='$constraint', state='$processing', date_started='$time' WHERE security_token='$security_token' AND state='$pending';";
		$result = $db->query($query);
		
		return $db->countAffected() == 1;
	}

	public static function setComplete(\Registry $registry, $security_token, $error = null){
		$db = $registry->get('db');
		$table = DB_PREFIX . self::getTableName();
		$processing = self::STATE_PROCESSING;
		$status = self::STATE_SUCCESS;
		if ($error) {
			$error = $db->escape($error);
			$status = self::STATE_ERROR;
		}
		$security_token = $db->escape($security_token);
		$time = new \DateTime();
		$time = $time->format('Y-m-d H:i:s');
		
		$query = "UPDATE $table SET `constraint_key`=id, `state`='$status', date_completed='$time', `error_message`='$error' WHERE `security_token`='$security_token' AND `state`='$processing';";
		$result = $db->query($query);
		
		return $db->countAffected() == 1;
	}

	public static function cleanUpHangingCrons(\Registry $registry){
		$db = $registry->get('db');
		\WalleeHelper::instance($registry)->dbTransactionStart();
		$time = new \DateTime();
		$time->add(new \DateInterval('PT1M'));
		$processing = self::STATE_PROCESSING;
		$error = self::STATE_ERROR;
		$timeout_message = 'Cron did not terminate correctly, timeout exceeded.';
		$table = DB_PREFIX . self::getTableName();
		try {
			$timeout = new \DateTime();
			$timeout->sub(new \DateInterval('PT' . self::TIMEOUT_MINUTES . 'M'));
			$timeout = $timeout->format('Y-m-d H:i:s');
			$end_time = new \DateTime();
			$end_time = $end_time->format('Y-m-d H:i:s');
			$query = "UPDATE $table SET constraint_key=id, `state`='$error', date_completed='$end_time', error_message='$timeout_message' WHERE `state`='$processing' AND date_started<'$timeout';";
			$db->query($query);
			\WalleeHelper::instance($registry)->dbTransactionCommit();
		}
		catch (\Exception $e) {
			\WalleeHelper::instance($registry)->dbTransactionRollback();
			\WalleeHelper::instance($registry)->log('Error clean up hanging cron: ' . $e->getMessage());
		}
	}

	public static function insertNewPendingCron(\Registry $registry){
		$db = $registry->get('db');
		\WalleeHelper::instance($registry)->dbTransactionStart();
		$pending = self::STATE_PENDING;
		$table = DB_PREFIX . self::getTableName();
		try {
			$hasQuery = "SELECT security_token FROM $table WHERE `state`='$pending';";
			$result = $db->query($hasQuery);
			if ($result->num_rows == 1) {
				\WalleeHelper::instance($registry)->dbTransactionCommit();
				return false;
			}
			$uuid = \WalleeHelper::generateUuid();
			$constraint = self::CONSTRAINT_PENDING;
			$time = new \DateTime();
			$time->add(new \DateInterval('PT1M'));
			$time = $time->format('Y-m-d H:i:s');
			$insertQuery = "INSERT INTO $table (constraint_key, state, security_token, date_scheduled) VALUES ('$constraint', '$pending', '$uuid', '$time');";
			$db->query($insertQuery);
			\WalleeHelper::instance($registry)->dbTransactionCommit();
			return $db->countAffected() == 1;
		}
		catch (\Exception $e) {
			\WalleeHelper::instance($registry)->dbTransactionRollback();
		}
		return false;
	}

	/**
	 * Returns the current token or false if no pending job is scheduled to run
	 *
	 * @return string|false
	 */
	public static function getCurrentSecurityTokenForPendingCron(\Registry $registry){
		try {
			$db = $registry->get('db');
			\WalleeHelper::instance($registry)->dbTransactionStart();
			$time = new \DateTime();
			$time->add(new \DateInterval('PT1M'));
			$pending = self::STATE_PENDING;
			$table = DB_PREFIX . self::getTableName();
			$now = new \DateTime();
			$now = $now->format('Y-m-d H:i:s');
			$query = "SELECT security_token FROM $table WHERE `state`='$pending' AND date_scheduled<'$now';";
			
			$result = $db->query($query);
			\WalleeHelper::instance($registry)->dbTransactionCommit();
			
			if ($result->num_rows) {
				return $result->row['security_token'];
			}
			return false;
		}
		catch (\Exception $e) {
			\WalleeHelper::instance($registry)->dbTransactionRollback();
			return false;
		}
	}
}