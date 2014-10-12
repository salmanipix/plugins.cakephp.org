<?php
App::uses('DataSource', 'Model/Datasource');
App::uses('HttpSocket', 'Network/Http');

class GithubSource extends DataSource {

/**
 * Holds a list of sources (tables) contained in the DataSource
 *
 * @var array
 */
	protected $_sources = array(
		'githubs',
		'issues',
		'repositories',
		'users',
	);

	protected $mapping = array(
		'repository' => '/repos/:owner/:repo/:_action',
		'user' => '/users/:user/:_action',
	);

	public $_schema = array(
		'githubs' => array(),
		'users' => array(),
		'issues' => array(),
		'repositories' => array(),
	);

	public $cacheSources = false;

	protected static $_error = null;

	public function __construct($config) {
		$config = array_merge(array(
			'host'      => 'api.github.com',
			'port'      => 443,
			'token'     => null,
			'database'  => 'api/v3/json',
			'cacheKey'  => 'github',
			'duration'  => '+2 days'
		), $config);

		$this->sConfig = array(
			'persistent' => '',
			'encoding' => 'utf-8',
			'header' => array(
				'Content-Type' => 'application/json',
				'encoding' => 'UTF-8',
				'Authorization' => sprintf('token %s', $config['token']),
			),
			'host' => $config['host'],
			'protocol' => '6',
			'port' => $config['port'],
			'timeout' => '30',
			'request' => array(
				'uri' => array(
					'scheme' => 'https',
					'host' => $config['host'],
					'port' => $config['port'],
				),
			),
		);

		$this->_token = $config['token'];
		if (!$config['token']) {
			unset($this->sConfig['header']['Authorization']);
		}

		parent::__construct($config);
	}

	public function __get($name) {
		if ($name === 'error') {
			return GithubSource::$_error;
		}

		trigger_error("Undefined property: GithubSource::$name");
	}

/**
 * Returns an empty array
 *
 * @param Object $model Model object to describe
 * @return array empty array
 */
	function describe($model) {
		$table = Inflector::tableize($model->alias);
		if (isset($this->_schema[$table])) {
			return $this->_schema[$table];
		}
		return array();
	}

/**
 * Returns an array of sources from github
 *
 * @return array Array of sources from github
 */
	public function listSources($data = null) {
		return $this->_sources;
	}

	public function read(Model $model, $queryData = array(), $recursive = NULL) {
		if (!$model->findQueryType) {
			return array();
		}
		$remove = array(
			'conditions', 'fields', 'joins', 'limit', 'offset',
			'order', 'page', 'group', 'callbacks'
		);
		$queryData = array_diff_key($queryData, array_combine($remove, $remove));

		$path = $this->mapping[$model->findQueryType];
		foreach ($queryData as $key => $value) {
			$path = str_replace(':' . $key, $value, $path);
		}

		$path = str_replace('/:_action', '', $path);
		$response = $this->_cachedResponse($path);
		$response = $this->_formatResponse($model->findQueryType, $queryData, $response);
		return $response;
	}

/**
 * Convenience method to fake out Model::_findMethods
 *
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	public function query() {
		$queryArgs = func_get_args();
		$method    = $queryArgs[0];
		$arguments = $queryArgs[1];

		if (substr($method, 0, 5) !== '_find') {
			return $arguments;
		}

		if (!is_array($arguments)) {
			return $arguments;
		}

		if ($arguments[0] == 'before') {
			return $arguments[1];
		} elseif ($arguments[0] == 'after') {
			return $arguments[2];
		}

		return $arguments;
	}

/**
 * Retrieves a response from github and caches it for some period of time
 *
 * @param string $request
 * @param string $var
 * @return void
 */
	protected function _cachedResponse($request, $var = null) {
		$hash = md5(serialize(array($request, $var)));
		$response = array();

		$sConfig = $this->sConfig;
		$token = $this->_token;
		Cache::set(array('duration' => $this->config['duration']));
		return Cache::remember($this->config['cacheKey'] . $hash, function() use ($sConfig, $token, $request, $var) {
			sleep(1);
			$url = sprintf("%s://%s%s",
				$sConfig['request']['uri']['scheme'],
				$sConfig['request']['uri']['host'],
				$request . $var
			);

			if (!empty($token)) {
				$url = sprintf("%s?access_token=%s", $url, $token);
			}

			$connection = new HttpSocket($sConfig);
			$response = $connection->get($url);
			if ($response->code == 404) {
				GithubSource::$_error = $response->reasonPhrase;
				return false;
			}

			$response = json_decode($response, true);

			if (!$response) {
				GithubSource::$_error = 'response was html page';
				return false;
			}

			if (isset($response['error'])) {
				GithubSource::$_error = $response['error'];
				return false;
			}

			return $response;
		});
	}

/**
 * Reformat a json_decode'd response to create cakephp-like response
 *
 * Fixes issues like pluralized model names and invalid CakePHP format
 *
 * @param array $data Data to be formatted
 * @return array
 */
	protected function _formatResponse($findQueryType, $queryData, $data) {
		if (!$data) {
			return array();
		}

		$response = array();
		if (isset($queryData['_action']) && !empty($queryData['_action'])) {
			foreach ($data as $k => $values) {
				$response[] = array($queryData['_action'] => $values);
			}
		} else {
			$response = array($findQueryType => $data);
		}

		$data = array();
		if (Set::numeric(array_keys($response))) {
			foreach ($response as $key => $record) {
				foreach ($record as $modelName => $values) {
					$m = Inflector::singularize(ucfirst($modelName));
					if ($m == 'Repo') {
						$m = 'Repository';
					}
					$data[$key][$m] = $values;
				}
			}
		} else {
			foreach ($response as $modelName => $keys) {
				$m = Inflector::singularize(ucfirst($modelName));
					if ($m == 'Repo') {
						$m = 'Repository';
					}
				$data[$m] = $keys;
			}
		}

		return $data;
	}

}
