<?php

namespace Wallee\Entity;

/**
 *
 * @method getId()
 * @method getCreatedAt()
 * @method getUpdatedAt()
 *
 * @method setId($id)
 * @method setCreatedAt($createdAt)
 * @method setUpdatedAt($updatedAt)
 *
 * Abstract implementation of a entity
 */
abstract class AbstractEntity {
	protected $data = array();
	protected $registry;

	protected static function getBaseFields(){
		return array(
			'id' => ResourceType::INTEGER,
			'created_at' => ResourceType::DATETIME,
			'updated_at' => ResourceType::DATETIME 
		);
	}

	protected static function getFieldDefinition() {
		throw new \Exception("Mock abstract, must be overwritten.");
	}

	protected static function getTableName() {
		throw new \Exception("Mock abstract, must be overwritten.");
	}

	protected function getValue($variable_name){
		return isset($this->data[$variable_name]) ? $this->data[$variable_name] : null;
	}

	protected function setValue($variable_name, $value){
		$this->data[$variable_name] = $value;
	}

	protected function hasValue($variable_name){
		return array_key_exists($variable_name, $this->data);
	}

	public function __call($name, $arguments){
		$variable_name = substr($name, 3);
		
		$cleaned = '';
		// first character should be upper
		for ($i = 0; $i < strlen($variable_name); $i++) {
			if (ctype_upper($variable_name[$i])) {
				$cleaned .= '_';
			}
			$cleaned .= $variable_name[$i];
		}
		$variable_name = substr(strtolower($cleaned), 1);
		
		if (0 === strpos($name, 'get')) {
			return $this->getValue($variable_name);
		}
		elseif (0 === strpos($name, 'set')) {
			$this->setValue($variable_name, $arguments[0]);
			return $this;
		}
		elseif (0 === strpos($name, 'has')) {
			return $this->hasValue($variable_name);
		}
	}

	public function __construct(){
		$args = func_get_args();
		if (!isset($args[0]) || !($args[0] instanceof \Registry)) {
			throw new \Exception("Registry must be supplied to entity objects.");
		}
		$this->registry = $args[0];
		
		if (!isset($args[1]) || empty($args[1])) {
			return;
		}
		$this->fillValuesFromDb($args[1]);
	}

	protected function fillValuesFromDb(array $db_values){
		$fields = array_merge($this->getBaseFields(), $this->getFieldDefinition());
		foreach ($fields as $key => $type) {
			if (isset($db_values[$key])) {
				$value = $db_values[$key];
				switch ($type) {
					case ResourceType::STRING:
						//Do nothing
						break;
					case ResourceType::BOOLEAN:
						$value = $value === 'Y';
						break;
					case ResourceType::INTEGER:
						$value = intval($value);
						break;
					
					case ResourceType::DECIMAL:
						$value = (float) $value;
						break;
					
					case ResourceType::DATETIME:
						$value = new \DateTime($value);
						break;
					
					case ResourceType::OBJECT:
						$value = unserialize($value);
						break;
					default:
						throw new \Exception('Unsupported variable type');
				}
				$this->setValue($key, $value);
			}
		}
	}

	public function save(){
		$db = $this->registry->get('db');
		$data_array = array();
		
		foreach ($this->getFieldDefinition() as $key => $type) {
			$value = $this->getValue($key);
			switch ($type) {
				case ResourceType::STRING:
					break;
				
				case ResourceType::BOOLEAN:
					$value = $value ? 'Y' : 'N';
					break;
				
				case ResourceType::INTEGER:
					break;
				
				case ResourceType::DATETIME:
					if ($value instanceof \DateTime) {
						$value = $value->format('Y-m-d H:i:s');
					}
					break;
				
				case ResourceType::OBJECT:
					$value = serialize($value);
					break;
				
				case ResourceType::DECIMAL:
					$value = number_format($value, 8, '.', '');
					break;
				
				default:
					throw new \Exception('Unsupported variable type');
			}
			$data_array[$key] = $value;
		}
		$data_array['updated_at'] = date("Y-m-d H:i:s");
		if ($this->getId() == null) {
			$data_array['created_at'] = $data_array['updated_at'];
		}
		
		$valuesQuery = '';
		
		foreach ($data_array as $key => $value) {
			$valuesQuery .= "`" . $key . '`="' . $db->escape($value) . '",';
		}
		
		$valuesQuery = rtrim($valuesQuery, ',');
		
		$table = DB_PREFIX . $this->getTableName();
		
		if ($this->getId() === null) {
			$query = "INSERT INTO $table SET $valuesQuery;";
			$res = $db->query($query);
			$this->setId($db->getLastId());
		}
		else {
			$query = "UPDATE $table SET $valuesQuery WHERE id = {$this->getId()};";
			$res = $db->query($query);
		}
	}

	/**
	 *
	 * @param int $id
	 * @return static
	 */
	public static function loadById(\Registry $registry, $id){
		$db = $registry->get('db');
		
		$result = $db->query("SELECT * FROM " . DB_PREFIX . static::getTableName() . " WHERE id = '$id';");
		
		if (isset($result->row) && !empty($result->row)) {
			return new static($registry, $result->row);
		}
		
		return new static($registry);
	}

	public static function loadAll(\Registry $registry){
		$db = $registry->get('db');
		
		$db_result = $db->query("SELECT * FROM " . DB_PREFIX . static::getTableName() . ";");
		
		$result = array();
		foreach ($db_result->rows as $row) {
			$result[] = new static($registry, $row);
		}
		return $result;
	}

	public function delete(\Registry $registry){
		$db = $registry->get('db');
		
		$query = "DELETE FROM " . DB_PREFIX . static::getTableName() . " WHERE id = {$this->getId};";
		$db->query($query);
	}
}