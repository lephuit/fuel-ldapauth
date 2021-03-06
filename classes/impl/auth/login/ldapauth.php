<?php
/**
 * LdapAuth is Ldap authentication package for FuelPHP.
 *
 * @package    LdapAuth
 * @version    1.0
 * @author     sharkpp
 * @license    MIT License
 * @copyright  2012+ sharkpp
 * @link       https://www.sharkpp.net/
 */

namespace LdapAuth;

/**
 * LdapAuth basic login driver
 *
 * @package     Fuel
 * @subpackage  Auth
 */
class Impl_Auth_Login_Ldapauth
{

	public static function _init()
	{
		\Fuel::$env == \Fuel::TEST ?: \Autoloader::add_class('Ldap', __DIR__.'/../../../ldap.php');

		\Config::load('ldapauth', true, true, true);

		//
		$driver_name = self::g('driver', 'Db');
		$class = 'Stateholder_'.ucfirst(\Inflector::denamespace($driver_name));
		self::$driver = new $class();
	}

	protected $owner = null;

	/**
	 * @var  Database_Result  when login succeeded
	 */
	protected $user = null;

	/**
	 * @var  array  value for guest login
	 */
	protected static $guest_login = array(
		'id'         => 'guest',
		'group'      => '0',
		'login_hash' => false,
		'email'      => 'john@example.net',
		'lastname'   => 'Doe',
		'firstname'  => 'John',
	);

	/**
	 * @var  array  LdapAuth class config
	 */
	protected $config = array(
		'drivers' => array('group' => array('LdapGroup')),
		'additional_fields' => array('profile_fields'),
	);

	private $ldap = array(
				'conn' => null,
				'user' => array()
			);

	private static $driver = null;

	private static function g($key, $default = null)
	{
		return \Config::get('ldapauth.'.$key, $default);
	}

	private function get_user_dn($username)
	{
		$ldap = $this->ldap['conn'];

		if( !$ldap )
		{
			logger(\Fuel::L_ERROR, 'not connection to ldap server');
			return false;
		}

		// ldap サーバーにバインドする
		$r = $ldap->bind(self::g('username', ''), self::g('password'));
		if( !$r )
		{
			logger(\Fuel::L_ERROR, 'bind error in "'.self::g('username', '').'": "'.$ldap->error().'"');
			return false;
		}

		$filter = array();
		foreach(array('account', 'email', 'firstname', 'lastname') as $filter_item)
		{
			if( self::g($filter_item) ) {
				$filter[] = self::g($filter_item);
			}
		}
		if( empty($filter) ) {
			$filter = null;
		}

		$query = '(&('.self::g('account', 'sAMAccountName').'='.$username.')(objectClass=*))';
		$sr = $ldap->search(self::g('basedn'), $query, $filter);
		if( !$sr )
		{
			logger(\Fuel::L_DEBUG, 'search error in "'.self::g('username', '').'": "'.$ldap->error().'"');
			return false;
		}

		logger(\Fuel::L_DEBUG, 'query = "'.$query.'"');
		logger(\Fuel::L_DEBUG, 'filter = '.print_r($filter,true).'');

		$ent = $sr->get_entries();
		if( false === $ent ||
			!isset($ent[0]['dn']) )
		{
			logger(\Fuel::L_DEBUG, 'get entries error in "'.self::g('username', '').'": "'.$ldap->error().'" "'.$query.'"');
			return false;
		}

	/*	if( !$ldap->unbind() )
		{
			logger(\Fuel::L_DEBUG, 'unbind error in "'.self::g('username', '').'": "'.$ldap->error().'"');
		}*/

		$userdn = $ent[0]['dn'];

		logger(\Fuel::L_DEBUG, 'user dn = "'.$userdn.'"');
		logger(\Fuel::L_DEBUG, 'entry = '.print_r($ent,true));

		$email_field     = self::g('email', '*');
		$firstname_field = self::g('firstname', '*');
		$lastname_field  = self::g('lastname', '*');
		$firstname       = \Arr::get($ent, '0.'.$firstname_field.'.0', \Arr::get($ent, strtolower('0.'.$firstname_field.'.0'), ''));
		$lastname        = \Arr::get($ent, '0.'.$lastname_field.'.0',  \Arr::get($ent, strtolower('0.'.$lastname_field.'.0'),  ''));
		$email           = \Arr::get($ent, '0.'.$email_field.'.0',     \Arr::get($ent, strtolower('0.'.$email_field.'.0'),     false));

		$this->ldap['user'] =
			array(
					'id'             => $username,
					'group'          => '1',
					'login_hash'     => false,
					'email'          => $email,
					'lastname'       => $lastname,
					'firstname'      => $firstname,
					'profile_fields' => $firstname,
				);
		logger(\Fuel::L_DEBUG, 'user = '.print_r($this->ldap['user'],true).'');

		return $userdn;
	}

	private function auth_user($userdn, $password)
	{
		$ldap = $this->ldap['conn'];

		if( !$ldap )
		{
			logger(\Fuel::L_DEBUG, 'not connection to ldap server');
			return false;
		}

		if( !$ldap->bind($userdn, $password) )
		{
			logger(\Fuel::L_ERROR, 'bind error in "'.$userdn.'": "'.$ldap->error().'"');
			return false;
		}

		if( !$ldap->unbind() )
		{
			logger(\Fuel::L_DEBUG, 'unbind error in "'.$userdn.'": "'.$ldap->error().'"');
		}

		return true;
	}

	function __construct($owner, Array $config)
	{
		$this->owner = $owner;

		// ldapサーバーと接続
		$uri = sprintf('%s://%s:%d/'
							, self::g('secure', false) ? 'ldaps' : 'ldap'
							, self::g('host', 'localhost')
							, intval(self::g('port', '389'))
						);

		$this->ldap['conn'] = \Ldap::connect($uri);
		if( !$this->ldap['conn'] )
		{
			logger(\Fuel::L_ERROR, 'can not connect to ldap server "'.self::g('driver', 'Db').'" "'.$uri.'"');
		}
		else
		{
			logger(\Fuel::L_DEBUG, 'connect to "'.$uri.'" -> '.str_replace("\n", '', var_export($this->ldap['conn'], true)));

			// for Windows Server
			$this->ldap['conn']->set_option(LDAP_OPT_PROTOCOL_VERSION, 3);
			$this->ldap['conn']->set_option(LDAP_OPT_REFERRALS, 0);
		}
	}

	/**
	 * Check for login
	 *
	 * @return  bool
	 */
	public function perform_check()
	{
//$bt=debug_backtrace(0);array_walk($bt, function(&$item, $key){$item=sprintf('%s(%d)',\Arr::get($item,'file',''),\Arr::get($item,'line',''));});logger(\Fuel::L_DEBUG, __METHOD__.'() '.print_r($bt,true));
		$username    = \Session::get('ldapauth.username');
		$login_hash  = \Session::get('ldapauth.login_hash');

		logger(\Fuel::L_DEBUG, 'L'.__LINE__.' '.__METHOD__.'() username="'.\Session::get('ldapauth.username').'" login_hash="'.\Session::get('ldapauth.login_hash').'"');

		// only worth checking if there's both a username and login-hash
		if ( ! empty($username) and ! empty($login_hash))
		{
			logger(\Fuel::L_DEBUG, __FILE__.'('.__LINE__.'):'.print_r($this->user,true));

			if (is_null($this->user) or ($this->user['id'] != $username and $this->user != static::$guest_login))
			{
				$this->user = self::$driver->search($username);
			}

			// return true when login was verified
			if ($this->user and $this->user['login_hash'] === $login_hash)
			{
				return true;
			}
		}

		// no valid login when still here, ensure empty session and optionally set guest_login
		$this->user = self::g('guest_login', true) ? static::$guest_login : false;
		\Session::delete('ldapauth.username');
		\Session::delete('ldapauth.login_hash');

		return false;
	}

	/**
	 * Check the user exists before logging in
	 *
	 * @return  bool
	 */
	public function validate_user($username_or_email = '', $password = '')
	{
		$username_or_email = trim($username_or_email) ?: trim(\Input::post(self::g('username_post_key', 'username')));
		$password = trim($password) ?: trim(\Input::post(self::g('password_post_key', 'password')));

		logger(\Fuel::L_DEBUG, __METHOD__.'() username_or_email="'.$username_or_email.'" password="'.substr($password,0,1).str_pad('', strlen($password)-1, '*').'"');

		$this->user = null;

		if (empty($username_or_email) or empty($password))
		{
			return false;
		}

		if( false === ($userdn = $this->get_user_dn($username_or_email)) )
		{
			return false;
		}

		if( !$this->auth_user($userdn, $password) )
		{
			return false;
		}

		$this->user = $this->ldap['user'];

		self::$driver->update($this->user);

		return $this->user ?: false;
	}

	/**
	 * Login user
	 *
	 * @param   string
	 * @param   string
	 * @return  bool
	 */
	public function login($username_or_email = '', $password = '')
	{
		if ( false === $this->validate_user($username_or_email, $password) )
		{
logger(\Fuel::L_DEBUG, 'L'.__LINE__.' '.__METHOD__.'()');
			$this->user = self::g('guest_login', true) ? static::$guest_login : false;
			\Session::delete('ldapauth.username');
			\Session::delete('ldapauth.login_hash');
			return false;
		}

logger(\Fuel::L_DEBUG, 'L'.__LINE__.' '.__METHOD__.'()');
		\Session::set('ldapauth.username', $this->user['id']);
		\Session::set('ldapauth.login_hash', $this->create_login_hash());
		\Session::instance()->rotate();
		return true;
	}

	/**
	 * Force login user
	 *
	 * @param   string
	 * @return  bool
	 */
	public function force_login($user_id = '')
	{
		if (empty($user_id))
		{
			return false;
		}

		$this->user = self::$driver->search($user_id);

		if ( false === $this->user )
		{
logger(\Fuel::L_DEBUG, 'L'.__LINE__.' '.__METHOD__.'()');
			$this->user = self::g('guest_login', true) ? static::$guest_login : false;
			\Session::delete('ldapauth.username');
			\Session::delete('ldapauth.login_hash');
			return false;
		}

logger(\Fuel::L_DEBUG, 'L'.__LINE__.' '.__METHOD__.'()');
		\Session::set('ldapauth.username', $this->user['id']);
		\Session::set('ldapauth.login_hash', $this->create_login_hash());
		return true;
	}

	/**
	 * Logout user
	 *
	 * @return  bool
	 */
	public function logout()
	{
		if ($this->user) {
			self::$driver->clear_hash($this->user['id'], $this->user['login_hash']);
		}
		$this->user = self::g('guest_login', true) ? static::$guest_login : false;
		\Session::delete('ldapauth.username');
		\Session::delete('ldapauth.login_hash');
		return true;
	}

	/**
	 * Create new user
	 *
	 * @param   string
	 * @param   string
	 * @param   string  must contain valid email address
	 * @param   int     group id
	 * @param   Array
	 * @return  bool
	 */
	public function create_user($username, $password, $email, $group = 1, Array $profile_fields = array())
	{
return false;
		$password = trim($password);
		$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);

		if (empty($username) or empty($password) or empty($email))
		{
			throw new LdapUserUpdateException('Username, password and email address can\'t be empty.', 1);
		}

		$same_users = \DB::select_array(\Config::get('ldapauth.table_columns', array('*')))
			->where('username', '=', $username)
			->or_where('email', '=', $email)
			->from(\Config::get('ldapauth.table_name'))
			->execute(\Config::get('ldapauth.db_connection'));

		if ($same_users->count() > 0)
		{
			if (in_array(strtolower($email), array_map('strtolower', $same_users->current())))
			{
				throw new LdapUserUpdateException('Email address already exists', 2);
			}
			else
			{
				throw new LdapUserUpdateException('Username already exists', 3);
			}
		}

		$user = array(
			'username'        => (string) $username,
			'password'        => $this->hash_password((string) $password),
			'email'           => $email,
			'group'           => (int) $group,
			'profile_fields'  => serialize($profile_fields),
			'created_at'      => \Date::forge()->get_timestamp()
		);
		$result = \DB::insert(\Config::get('ldapauth.table_name'))
			->set($user)
			->execute(\Config::get('ldapauth.db_connection'));

		return ($result[1] > 0) ? $result[0] : false;
	}

	/**
	 * Update a user's properties
	 * Note: Username cannot be updated, to update password the old password must be passed as old_password
	 *
	 * @param   Array  properties to be updated including profile fields
	 * @param   string
	 * @return  bool
	 */
	public function update_user($values, $username = null)
	{
return false;
		$username = $username ?: $this->user['username'];
		$current_values = \DB::select_array(\Config::get('ldapauth.table_columns', array('*')))
			->where('username', '=', $username)
			->from(\Config::get('ldapauth.table_name'))
			->execute(\Config::get('ldapauth.db_connection'));

		if (empty($current_values))
		{
			throw new LdapUserUpdateException('Username not found', 4);
		}

		$update = array();
		if (array_key_exists('username', $values))
		{
			throw new LdapUserUpdateException('Username cannot be changed.', 5);
		}
		if (array_key_exists('password', $values))
		{
			if (empty($values['old_password'])
				or $current_values->get('password') != $this->hash_password(trim($values['old_password'])))
			{
				throw new \LdapUserWrongPassword('Old password is invalid');
			}

			$password = trim(strval($values['password']));
			if ($password === '')
			{
				throw new LdapUserUpdateException('Password can\'t be empty.', 6);
			}
			$update['password'] = $this->hash_password($password);
			unset($values['password']);
		}
		if (array_key_exists('old_password', $values))
		{
			unset($values['old_password']);
		}
		if (array_key_exists('email', $values))
		{
			$email = filter_var(trim($values['email']), FILTER_VALIDATE_EMAIL);
			if ( ! $email)
			{
				throw new LdapUserUpdateException('Email address is not valid', 7);
			}
			$update['email'] = $email;
			unset($values['email']);
		}
		if (array_key_exists('group', $values))
		{
			if (is_numeric($values['group']))
			{
				$update['group'] = (int) $values['group'];
			}
			unset($values['group']);
		}
		if ( ! empty($values))
		{
			$profile_fields = @unserialize($current_values->get('profile_fields')) ?: array();
			foreach ($values as $key => $val)
			{
				if ($val === null)
				{
					unset($profile_fields[$key]);
				}
				else
				{
					$profile_fields[$key] = $val;
				}
			}
			$update['profile_fields'] = serialize($profile_fields);
		}

		$affected_rows = \DB::update(\Config::get('ldapauth.table_name'))
			->set($update)
			->where('username', '=', $username)
			->execute(\Config::get('ldapauth.db_connection'));

		// Refresh user
		if ($this->user['username'] == $username)
		{
			$this->user = \DB::select_array(\Config::get('ldapauth.table_columns', array('*')))
				->where('username', '=', $username)
				->from(\Config::get('ldapauth.table_name'))
				->execute(\Config::get('ldapauth.db_connection'))->current();
		}

		return $affected_rows > 0;
	}

	/**
	 * Change a user's password
	 *
	 * @param   string
	 * @param   string
	 * @param   string  username or null for current user
	 * @return  bool
	 */
	public function change_password($old_password, $new_password, $username = null)
	{
		return false;
	}

	/**
	 * Generates new random password, sets it for the given username and returns the new password.
	 * To be used for resetting a user's forgotten password, should be emailed afterwards.
	 *
	 * @param   string  $username
	 * @return  string
	 */
	public function reset_password($username)
	{
		return '';
	}

	/**
	 * Deletes a given user
	 *
	 * @param   string
	 * @return  bool
	 */
	public function delete_user($username)
	{
return false;
		if (empty($username))
		{
			throw new LdapUserUpdateException('Cannot delete user with empty username', 9);
		}

		$affected_rows = \DB::delete(\Config::get('ldapauth.table_name'))
			->where('username', '=', $username)
			->execute(\Config::get('ldapauth.db_connection'));

		return $affected_rows > 0;
	}

	/**
	 * Creates a temporary hash that will validate the current login
	 *
	 * @return  string
	 */
	public function create_login_hash()
	{
		if( !self::$driver )
		{
			return false;
		}

		if (empty($this->user))
		{
			throw new LdapUserUpdateException('User not logged in, can\'t create login hash.', 10);
		}

		$login_hash = self::$driver->create_hash($this->user['id'], self::g('create_when_not_found', false));

		$this->user['login_hash'] = $login_hash;

		return $login_hash;
	}

	/**
	 * Get the user's ID
	 *
	 * @return  Array  containing this driver's ID & the user's ID
	 */
	public function get_user_id()
	{
		if (empty($this->user))
		{
			return false;
		}

		return array($this->owner->get_id(), $this->user['id']);
	}

	/**
	 * Get the user's groups
	 *
	 * @return  Array  containing the group driver ID & the user's group ID
	 */
	public function get_groups()
	{
		if (empty($this->user))
		{
			return false;
		}

		return array(array('LdapGroup', $this->user['group']));
	}

	/**
	 * Get the user's emailaddress
	 *
	 * @return  string
	 */
	public function get_email()
	{
		if (empty($this->user))
		{
			return false;
		}

		return $this->user['email'];
	}

	/**
	 * Get the user's screen name
	 *
	 * @return  string
	 */
	public function get_screen_name()
	{
		if (empty($this->user))
		{
			return false;
		}

		return $this->user['firstname'] . ' ' . $this->user['lastname'];
	}

	/**
	 * Get the user's profile fields
	 *
	 * @return  Array
	 */
	public function get_profile_fields($field = null, $default = null)
	{
		if (empty($this->user))
		{
			return false;
		}

		if (isset($this->user['profile_fields']))
		{
			is_array($this->user['profile_fields']) or $this->user['profile_fields'] = @unserialize($this->user['profile_fields']);
		}
		else
		{
			$this->user['profile_fields'] = array();
		}

		return $this->user['profile_fields'];
	}

	/**
	 * Extension of base driver method to default to user group instead of user id
	 */
	public function has_access($condition, $driver = null, $user = null)
	{
		if (is_null($user))
		{
			$groups = $this->get_groups();
			$user = reset($groups);
		}
		return call_user_func(array($this->owner, '\\Auth_Login_Driver::has_access'), $condition, $driver, $user);
	}

	/**
	 * Extension of base driver because this supports a guest login when switched on
	 */
	public function guest_login()
	{
		return self::g('guest_login', true);
	}
}

// end of file ldapauth.php
