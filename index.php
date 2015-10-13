<?php

#error_reporting(-1);
#ini_set('display_errors', 1);
#error_reporting(E_ALL);

require "password.php";

/**
 * REST API
 *
 * Method  URI                                                     Access  Description
 * GET     http://api.dose.se/dosepics/users/                      Any     Return list of user resources (returns JSON)
 * GET     http://api.dose.se/dosepics/users/guest                 Any     Return information about user guest (returns JSON)
 * GET     http://api.dose.se/dosepics/users/guest/pics            Any     Return list of image resources owned by user guest (returns JSON)
 * GET     http://api.dose.se/dosepics/pics/                       Any     Return list of all image resources (returns JSON)
 * GET     http://api.dose.se/dosepics/pics/42                     Any     Return information about image 42 (returns JSON)
 * GET     http://api.dose.se/dosepics/pics/42/pic                 Any     Return actual image 42 (returns JPEG)
 * GET     http://api.dose.se/dosepics/pics/42/thumb               Any     Return thumbnail of image 42 (returns JPEG)
 * DELETE  http://api.dose.se/dosepics/users/guest                 Admin   Delete user guest
 * DELETE  http://api.dose.se/dosepics/pics/42                     User    Delete image 42
 * POST    http://api.dose.se/dosepics/users                       Admin   Create user resource. Returns user resource location in response header (i.e., the very same resource that was specified in the request message payload)
 * POST    http://api.dose.se/dosepics/pics                        User    Create image resource. Returns the server-generated user resource location in response header
 * PUT     http://api.dose.se/dosepics/users/guest                 User    Update user resource guest
 * PUT     http://api.dose.se/dosepics/users/guest/pwd             User    Update password of user resource guest
 * PUT     http://api.dose.se/dosepics/users/guest/name            User    Update real name of user resource guest
 * PUT     http://api.dose.se/dosepics/users/guest/admin           Admin   Update the administrator status of user resource guest
 * PUT     http://api.dose.se/dosepics/pics/42                     User    Update image resource 42
 * PUT     http://api.dose.se/dosepics/pics/42/pic                 User    Update the actual image of image resource 42
 * PUT     http://api.dose.se/dosepics/pics/42/info                User    Update the info of image resource 42
 * PUT     http://api.dose.se/dosepics/pics/42/owner               Admin   Update the owner of image resource 42
 * 
 */

// Global defines. Suit these for your own needs
define ("HOST", "localhost");
define ("DATABASE", "dosepics");
define ("DB_USERNAME", "dosepics");
define ("DB_PASSWORD", "dosep1cs");
define ("TABLE_USERS", "users");
define ("TABLE_PICS", "pics");
define ("THUMB_WIDTH", 200);
define ("BASE_IMAGE_DIR", '/var/www/api.dose.se/dosepics/pics/');
define ("BASE_THUMB_DIR", '/var/www/api.dose.se/dosepics/pics/thumbs/');
define ("BASE_WEB_URL", 'http://api.dose.se/dosepics');

// Default content type. If an image is returned, the content header will be replaced
header('Content-Type: application/json;charset=UTF-8');

/**
 * Connect to the database
 *
 * @return a PDO object, dies off if it fails to deliver
 */
function connect_database()
{
	$host = HOST;
	$database = DATABASE;
	try {
		$db = new PDO("mysql:host=$host;dbname=$database", DB_USERNAME, DB_PASSWORD);
	} catch (PDOException $e)
	{
        header('HTTP/1.1 500 Internal Server Error');
		die("Database connection failed");
	}
	return $db;
}

/**
 * Get a clean URL in array form. Remove the leading /dosepics/ and excessive slashes
 *
 * For example, if the call was made to 
 *
 * http://www.example.com/dosepics/users/guest/pics ,
 *
 * this function will return: array('users', 'guest', 'pics');
 *
 * @return an array with a nice clean URL
 *
 */
function get_clean_url()
{
	// Get the request URI
	$start_url = parse_url($_SERVER['REQUEST_URI']);

	// Get only the path part
	$start_path = $start_url['path'];

	// Remove all duplicate slashes within the path
	$cleaner_url = preg_replace('/(\/+)/','/',$start_path);

	// Remove preceding slash
	$cleaner_url = rtrim($cleaner_url, '/');

	// Remove trailing slash
	$cleaner_url = ltrim($cleaner_url, '/');

	// Convert string into array split between slashes
	$paths = explode('/', $cleaner_url);

	// Remove the first part of the path ('/dosepics')
	$paths = array_slice($paths, 1);
	return $paths;
}

/**
 * Create a thumbnail of an image
 *
 * @param	$pic	Image filename
 *
 * @return	Thumbnal image
 */
function create_thumbnail($pic)
{
	$baseurl = BASE_IMAGE_DIR;
	$thumb_width = THUMB_WIDTH;

    $image = imagecreatefromjpeg($baseurl.$pic);
    $image_size = getimagesize($baseurl.$pic);
    $image_width = $image_size[0];
    $image_height = $image_size[1];
    $thumb_height = ($image_height / $image_width) * $thumb_width;

    // Create an empty image with the preferred layout
    $new_img = imagecreatetruecolor($thumb_width, $thumb_height);
    imagecopyresampled($new_img, $image,
        0,                  // Destination X
        0,                  // Destination Y
        0,                  // Source X
        0,                  // Source Y
        $thumb_width,       // Destination width
        $thumb_height,      // Destination height
        imagesx($image),    // Source width
        imagesy($image));   // Source height
    return $new_img;
}

/**
 * Checks whether the request was made by an admin user
 *
 * @return	true if user is admin, false otherwise
 * 
 */
function authenticate_admin()
{
	// What username / password were we called with?
	$pwd = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : null;
	$username = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : null;
	if(is_null($pwd) || is_null($username))
		return false;

	// Get username from database
	$users_table = TABLE_USERS;
	$db = connect_database();
	$res = $db->prepare("SELECT pwd, admin FROM $users_table WHERE username=?");
	$data = array($username);
	$res->execute($data);
	
	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		return false;
	}

	// Return the one (and only!) result row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	if($row['admin'] && password_verify($pwd, $row['pwd']))
		return true;
	else
		return false;
}

/**
 * Authenticates the user
 *
 * @param	$user	User to be authenticated
 *
 * @return	true if user is authenticated, false otherwise
 *
 */
function authenticate_user($user)
{
	$user_to_authenticate = $user;

	// What username / password were we called with?
	$pwd = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : null;
	$username = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : null;
	if(is_null($pwd) || is_null($username))
		return false;

	// Is the user trying to modify her own resources?
	if($user_to_authenticate != $username)
		return false;

	// Get username from database
	$users_table = TABLE_USERS;
	$db = connect_database();
	$res = $db->prepare("SELECT pwd, admin FROM $users_table WHERE username=?");
	$data = array($username);
	$res->execute($data);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		return false;
	}

	// Return the one (and only!) result row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	if(password_verify($pwd, $row['pwd']))
		return true;
	else
		return false;
}

/**
 * Finds the owner of an image resource
 *
 * @return	owner as string, dies otherwise
 *
 */
function get_owner_of_pic_resource($resource)
{
	$pics_table = TABLE_PICS;
	$db = connect_database();
	$res = $db->prepare("SELECT owner FROM $pics_table WHERE id=?");
	$input = array($resource);
	$res->execute($input);

	// Return the one (and only!) result row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	if($row == null)
	{
       	header('HTTP/1.1 404 Not Found');
		die();
	}

	$owner = $row['owner'];

	if(is_null($owner))
	{
		header("HTTP/1.1 403 Forbidden");
		die();
	}
	return $owner;
}

/**
 * Make sure that the caller has specified a picture or user to be deleted
 */
function handle_delete()
{
	$paths = get_clean_url();

	if(isset($paths[0]) && isset($paths[1]) && !isset($paths[2]))
	{
		$resource = $paths[1];
		if($paths[0] == 'pics')
		{
			handle_delete_pic($resource);
		}
		else if($paths[0] == 'users')
			handle_delete_user($resource);
		else
		{
			header('HTTP/1.1 400 Bad Request');
			die();
		}
		return;
	}

	// Only /users and /pics are allowed
	header('HTTP/1.1 400 Bad Request');
	die();
}

/**
 * @param	pic		Picture resource to be deleted	
 */
function handle_delete_pic($pic)
{

	$db = connect_database();
	$pics_table = TABLE_PICS;

	// If the caller is admin, approve of the action, otherwise
	// only approve if the caller is the owner of the resource
	if(!authenticate_admin())
	{
		// Are we the owner of this picture?
		$owner = get_owner_of_pic_resource($pic);
		if(!authenticate_user($owner))
		{
			header("HTTP/1.1 403 Forbidden");
			die("You can only delete your own images");
		}
	}

	$res = $db->prepare("DELETE FROM $pics_table WHERE id=:pic");
	$input = array('pic' => $pic);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
        header('HTTP/1.1 404 Not Found');
		die("Pic not found");
	}

	// FIXME: Delete actual image

}

/*
 * @param	user	Username resource to be deleted
 */
function handle_delete_user($user)
{
	if(!authenticate_admin())
	{
		header("HTTP/1.1 403 Forbidden");
		die();
	}
	$db = connect_database();
	$users_table = TABLE_USERS;

	$res = $db->prepare("DELETE FROM $users_table WHERE username=:user");
	$input = array('user' => $user);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 404 Not Found');
		die("User not found");
	}
}

/**
 * Make sure we are called with a valid URI
 *
 */
function handle_get()
{
	$paths = get_clean_url();

	// Only '/users' or '/pics' supplied ?
	if(isset($paths[0]) && !isset($paths[1]))
	{
		if($paths[0] == 'pics')
		{
			handle_get_pics();
			return;
		} else if($paths[0] == 'users')
		{
			handle_get_users();
			return;
		}
	} else if(isset($paths[0]) && isset($paths[1]) && !isset($paths[2]))
	{
		// '/users/{user_id}' ?
		if($paths[0] == 'pics')
		{
			$resource = $paths[1];
			handle_get_pic_info($resource);
			return;
		} else if($paths[0] == 'users')
		{
			// 'pics/{pic_id}' ?
			$resource = $paths[1];
			handle_get_user($resource);
			return;
		}
	} else if(isset($paths[0]) && isset($paths[1]) && isset($paths[2]) && !isset($paths[3]))
	{
		// '/users/{user_id}/pics' ?
		if($paths[0] == 'users' && $paths[2] == 'pics')
		{
			$user = $paths[1];
			handle_get_user_images($user);
			return;
		} else if($paths[0] == 'pics')
		{
			$resource = $paths[1];
			// '/pics/{pic_id}/pic'
			if($paths[2] == 'pic')
				handle_get_pic($resource);
			// '/pics/{pic_id}/thumb'
			else if($paths[2] == 'thumb')
				handle_get_thumb($resource);
			// '/pics/{pic_id}/swipe
			else if($paths[2] == 'swipe')
				handle_get_swipe_image($resource);
			else
			{
				header('HTTP/1.1 400 Bad Request');
				die();
			}
			return;
		}
	}

	header('HTTP/1.1 400 Bad Request');
	die();
}

/**
 * Return a list of all image resources
 */
function handle_get_pics()
{
	$pics_table = TABLE_PICS;

	$db = connect_database();
	$res = $db->query("SELECT * FROM $pics_table");

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}
	
	if($res->rowCount() == 0)
	{
		header('HTTP/1.1 404 Not Found');
		die();
	}

	$users = array();
	foreach($res as $row)
	{
		array_push($users, $row['id']);
	}
	echo json_encode($users);
}

/**
 * Return information about an image resource
 *
 * @param	$pic	Picture resource to return
 */
function handle_get_pic_info($pic)
{
	$pics_table = TABLE_PICS;

	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $pics_table WHERE id=:pic");
	$input = array('pic' => $pic);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Return the next (and only!) row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	$pic = array(
			'id' => $row['id'],
			'owner' => $row['owner'],
			'info' => $row['info']);

	$obj = json_encode($pic);
	echo $obj;
}

/**
 * Return actual image
 *
 * @param	$pic	Picture resource's image to return
 */
function handle_get_pic($pic)
{
	$pics_table = TABLE_PICS;

	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $pics_table WHERE id=:pic");
	$input = array('pic' => $pic);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Return the next (and only!) row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	$filename = $row['filename'];
	$base_dir = BASE_IMAGE_DIR;

	$image = imagecreatefromjpeg($base_dir.$filename);
	header("Content-Type: image/jpeg");
	imagejpeg($image);
}

/**
 * Return image thumbnail
 *
 * @param	$pic	Picture resource's image to return
 */
function handle_get_thumb($pic)
{
	$pics_table = TABLE_PICS;

	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $pics_table WHERE id=:pic");
	$input = array('pic' => $pic);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Return the next (and only!) row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	$filename = $row['filename'];
	$base_dir = BASE_THUMB_DIR;

	/*
	// Dynamic version
	header("Content-Type: image/jpeg");
	imagejpeg(create_thumbnail($filename));
	*/

	$image = imagecreatefromjpeg($base_dir.$filename);
	header("Content-Type: image/jpeg");
	imagejpeg($image);
}

/**
 * Get an image of suitable size fo the swipe activity of the Android app
 *
 * FIXME: Yeah, this really is an ugly hack. Find a better and more permanent solution.
 *        Cache images in multiple sizes
 *
 */
function handle_get_swipe_image($pic)
{
	$pics_table = TABLE_PICS;

	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $pics_table WHERE id=:pic");
	$input = array('pic' => $pic);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Return the next (and only!) row as an array indexed by column name
	$row = $res->fetch(PDO::FETCH_ASSOC);
	$filename = $row['filename'];
	$base_dir = BASE_THUMB_DIR;

	header("Content-Type: image/jpeg");
	imagejpeg(create_swipe_image(basename($filename)));
}
/**
 * Help function for the ugly hack above
 */
function create_swipe_image($pic)
{
	$baseurl = BASE_IMAGE_DIR;
	$swipe_width = 1600;

    $image = imagecreatefromjpeg($baseurl.$pic);
    $image_size = getimagesize($baseurl.$pic);
    $image_width = $image_size[0];
    $image_height = $image_size[1];
    $thumb_height = ($image_height / $image_width) * $swipe_width;

    // Create an empty image with the preferred layout
    $new_img = imagecreatetruecolor($swipe_width, $thumb_height);
    imagecopyresampled($new_img, $image,
        0,                  // Destination X
        0,                  // Destination Y
        0,                  // Source X
        0,                  // Source Y
        $swipe_width,       // Destination width
        $thumb_height,      // Destination height
        imagesx($image),    // Source width
        imagesy($image));   // Source height
    return $new_img;
}



/**
 * Return a list of all user resources
 */
function handle_get_users()
{
	$users_table = TABLE_USERS;

	$db = connect_database();
	$res = $db->query("SELECT * FROM $users_table");
	
	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}
	
	if($res->rowCount() == 0)
	{
		header('HTTP/1.1 404 Not Found');
		die();
	}

	$users = array();
	foreach($res as $row)
	{
		array_push($users, $row['username']);
	}
	echo json_encode($users);
}

/**
 * Return information about a user resource
 *
 * $param	$user	User resource to return
 */
function handle_get_user($user)
{
	$users_table = TABLE_USERS;

	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $users_table WHERE username=:user");
	$input = array('user' => $user);
	$res->execute($input);
	
	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// foreach, really? FIXME!
	foreach($res as $row)
	{
		$user = array(
				'username' => $row['username'],
				'name' => $row['name'],
				'admin' => (bool) $row['admin']);
		$obj = json_encode($user);
		echo $obj;
	}
}

/**
 * Return a list of image resources associated with the user resource
 *
 * $param	$user	User resource whose images shall be returned
 */
function handle_get_user_images($user)
{
	$pics_table = TABLE_PICS;

	$db = connect_database();

	// FIXME: First, check that user exists!

	$res = $db->prepare("SELECT * FROM $pics_table WHERE owner=:user");
	$input = array('user' => $user);
	$res->execute($input);
	
	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		header('HTTP/1.1 404 Not Found');
		die('User does not own any images');
	}

	$users = array();
	foreach($res as $row)
	{
		array_push($users, $row['id']);
	}
	echo json_encode($users);
}

/**
 * Make sure we are called with a valid URI
 *
 */
function handle_post()
{
	$paths = get_clean_url();

	if(isset($paths[0]) && !isset($paths[1]))
	{
		if($paths[0] == 'pics')
			handle_post_pic();
		else if($paths[0] == 'users')
			handle_post_user();
		else
		{
			header('HTTP/1.1 400 Bad Request');
			die();
		}
		return;
	}

	// Only /users and /pics are allowed
	header('HTTP/1.1 400 Bad Request');
	echo('Only users or pics can be POSTed');
	die();
}

/**
 * Make sure the caller has the right to do what is requested
 */
function handle_post_pic()
{
	create_pic_resource();
}

/**
 * Make sure we have the rights before continuing
 */
function handle_post_user()
{
	if(!authenticate_admin())
	{
		header("HTTP/1.1 403 Forbidden");
		die("Only administrators are allowed to create users");
	}
	create_user_resource();
}

/**
 * Make sure we are called with a valid URI
 *
 */
function handle_put()
{
	$paths = get_clean_url();

	// '/users/{user_id}' or '/pics/{pic_id}'
	if(isset($paths[0]) && isset($paths[1]) && !isset($paths[2]))
	{
		$resource = $paths[1];

		if($paths[0] == 'pics')
			handle_put_pic($resource);
		else if($paths[0] == 'users')
			handle_put_user($resource);
		else
		{
			header('HTTP/1.1 400 Bad Request');
			die();
		}
		return;
	}

	if(isset($paths[0]) && isset($paths[1]) && isset($paths[2]) && !isset($paths[3]))
	{
		if($paths[0] == 'pics')
		{
			$resource = $paths[1];

			// '/pics/{pic_id}/pic' ?
			if($paths[2] == 'pic')
				handle_put_pic_pic($resource);
			// '/pics/{pic_id}/info' ?
			else if($paths[2] == 'info')
				handle_put_pic_info($resource);
			// '/pics/{pic_id}/owner' ?
			else if($paths[2] == 'owner')
				handle_put_pic_owner($resource);
			else
			{
				header('HTTP/1.1 400 Bad Request');
				die();
			}
			return;
		} else if($paths[0] == 'users')
		{
			$resource = $paths[1];

			// '/users/{user_id}/pwd' ?
			if($paths[2] == 'pwd')
				handle_put_user_pwd($resource);
			// '/users/{user_id}/name' ?
			else if($paths[2] == 'name')
				handle_put_user_name($resource);
			// '/pics/{pic_id}/admin' ?
			else if($paths[2] == 'admin')
				handle_put_user_admin($resource);
			else
			{
				header('HTTP/1.1 400 Bad Request');
				die();
			}
			return;
		}
		header('HTTP/1.1 400 Bad Request');
		die();
		return;
	}

	// Only /users/{resource} and /pics/{resource} are allowed
	header('HTTP/1.1 400 Bad Request');
	die('Only users or pics can be PUT');
}

/**
 * Validate the body of the PUT request
 * Make sure all required fields are supplied
 * Also, since this update mandates an inclusion of the administrator flag,
 * only administrators can make this call
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_user($resource)
{
	$users_table = TABLE_USERS;
	$data = json_decode(file_get_contents("php://input"));

	// All fields must be supplied
	$name = isset($data->name) ? $data->name : null;
	$pwd = isset($data->pwd) ? $data->pwd : null;
	$admin = isset($data->admin) ? $data->admin : null;

	// An administrator can update whatever she wants
	if(!authenticate_admin())
	{
		header("HTTP/1.1 403 Forbidden");
		die("A request that includes an update to the administrator flag can only be made by an administrator");
	}

	// All fields must be supplied
	if(is_null($name) || is_null($pwd) || is_null($admin))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("name, pwd, info and admin fields must be supplied");
	}

	update_user_resource($resource, $pwd, $name, $admin);
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_user_pwd($resource)
{
	// An admin can update whatever she wants
	if(!authenticate_admin())
	{
		if(!authenticate_user($resource))
		{
			header("HTTP/1.1 403 Forbidden");
			die("You can only update your own items");
		}
	}

	$data = json_decode(file_get_contents("php://input"));

	$pwd = isset($data->pwd) ? $data->pwd : null;

	// The image_data field must be supplied
	if(is_null($pwd))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("pwd field must be supplied");
	}

	update_user_resource($resource, $pwd, null, null);
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_user_name($resource)
{
	// An admin can update whatever she wants
	if(!authenticate_admin())
	{
		if(!authenticate_user($resource))
		{
			header("HTTP/1.1 403 Forbidden");
			die("You can only update your own items");
		}
	}

	$data = json_decode(file_get_contents("php://input"));

	$name = isset($data->name) ? $data->name : null;

	// The image_data field must be supplied
	if(is_null($name))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("name field must be supplied");
	}

	update_user_resource($resource, null, $name, null);
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_user_admin($resource)
{
	if(!authenticate_admin())
	{
		header("HTTP/1.1 403 Forbidden");
		die("Only administrators can alter the administrator flag!");
	}

	$data = json_decode(file_get_contents("php://input"));

	$admin = isset($data->admin) ? $data->admin : null;

	// The image_data field must be supplied
	if(is_null($admin))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("admin field must be supplied");
	}

	update_user_resource($resource, null, null, $admin);
}

/**
 * Validate the body of the PUT request
 * Make sure all the required fields are supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_pic($resource)
{
	$owner = get_owner_of_pic_resource($resource);
	if(!authenticate_user($owner))
	{
		header("HTTP/1.1 403 Forbidden");
		die("You can only update your own resources!");
	}

	$data = json_decode(file_get_contents("php://input"));

	$owner = isset($data->owner) ? $data->owner : null;
	$info = isset($data->info) ? $data->info : null;
	$image_data = isset($data->image) ? $data->image : null;

	// All fields must be supplied
	if(is_null($owner) || is_null($info) || is_null($image_data))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("owner, info and image fields must be supplied");
	}

	update_pic_resource($resource, $owner, $info, $image_data);
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_pic_pic($resource)
{
	$db = connect_database();
	$pics_table = TABLE_PICS;

	$data = json_decode(file_get_contents("php://input"));
	$image_data = isset($data->image) ? $data->image : null;

	// If the caller is admin, approve of the action, otherwise
	// only approve if the caller is authenticated as the owner
	// of the resource
	if(authenticate_admin())
	{
		update_pic_resource($resource, null, null, $image_data);
		return;
	}

	$owner = get_owner_of_pic_resource($resource);

	if(authenticate_user($owner))
	{
		// OK, user is the owner of the resource
		update_pic_resource($resource, null, null, $image_data);
		return;
	} else
	{
		// User is neither admin nor the owner of the resource
		header("HTTP/1.1 403 Forbidden");
		die("You are not allowed to update this image!");
	}
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_pic_info($resource)
{
	$data = json_decode(file_get_contents("php://input"));

	$info = isset($data->info) ? $data->info : null;

	// If the caller is admin, approve of the action, otherwise
	// only approve if the caller is authenticated as the owner
	// of the resource
	if(authenticate_admin())
	{
		update_pic_resource($resource, null, $info, null);
		return;
	}

	$owner = get_owner_of_pic_resource($resource);

	if(authenticate_user($owner))
	{
		// OK, user is the owner of the resource
		update_pic_resource($resource, null, $info, null);
		return;
	} else
	{
		// User is neither admin nor the owner of the resource
		header("HTTP/1.1 403 Forbidden");
		die("You are not allowed to update this image!");
	}
}

/**
 * Validate the body of the PUT request
 * Make sure the required field is supplied
 *
 * @param	resource	The resource to be updated
 *
 */
function handle_put_pic_owner($resource)
{
	if(!authenticate_admin())
	{
		header("HTTP/1.1 403 Forbidden");
		die("Only administrators can change the owner of a picture!");
	}

	$data = json_decode(file_get_contents("php://input"));

	$owner = isset($data->owner) ? $data->owner : null;

	// The image_data field must be supplied
	if(is_null($owner))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("owner field must be supplied");
	}

	update_pic_resource($resource, $owner, null, null);
}

/**
 * Create a new user resource from infromation supplied in the request body
 */
function create_user_resource()
{
	$users_table = TABLE_USERS;

	$data = json_decode(file_get_contents("php://input"));

	$username = isset($data->username) ? $data->username : null;
	$pwd = isset($data->pwd) ? $data->pwd : null;
	$name = isset($data->name) ? $data->name : "";
	$admin = isset($data->admin) ? $data->admin : false;

	// Required fields
	if(is_null($username) || is_null($pwd))
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("Username and pwd must be supplied");
	}

	// Check whether user already exists
	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $users_table WHERE username=?");
	$input = array($username);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 1)
	{
		header('HTTP/1.1 409 Conflict');
		die("User already exists");
	}

	$pwd_hash = password_hash($pwd, PASSWORD_DEFAULT);

	// Update database
	$res = $db->prepare("INSERT INTO $users_table (username, pwd, name, admin)".
			"VALUES (?, ?, ?, ?)");
	$input = array(
			$username,
			$pwd_hash,
			$name,
			$admin);
	$res->execute($input);

	if($res->rowCount() == 0)
	{
		header('HTTP/1.1 422 Unprocessable Entity');
		die("User could not be added to the database");
	}

	// Resource created, return its location
	header('Location:'.BASE_WEB_URL.'/users/'.$username);
	header('HTTP/1.1 201 Created');
}

/**
 * Update an already existing user resource
 *
 * @param	$resource	Resource to be updated
 * @param	$pwd		Password of resource; if null, this field is not updated
 * @param	$name		Real name of resource; if null, this field is not updated
 * @param	$admin		Boolean value indicating whether the user had administrative rights; if null, this field is not updated
 *
 */
function update_user_resource($resource, $pwd, $name, $admin)
{
	$users_table = TABLE_USERS;

	// Check whether resource exists
	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $users_table WHERE username=?");
	$input = array($resource);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}


	// Resource does not exist?
	if($res->rowCount() != 1)
	{
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Update pwd
	if(!is_null($pwd))
	{
		$pwd_hash = password_hash($pwd, PASSWORD_DEFAULT);
		$res = $db->prepare("UPDATE $users_table SET pwd=? WHERE username=?");
		$input = array($pwd_hash, $resource);
		$res->execute($input);
		if(!($res))
		{
			header('HTTP/1.1 500 Internal Server Error');
			die("Database error");
		}
	}

	// Update name
	if(!is_null($name))
	{
		$res = $db->prepare("UPDATE $users_table SET name=? WHERE username=?");
		$input = array($name, $resource);
		$res->execute($input);
		if(!($res))
		{
			header('HTTP/1.1 500 Internal Server Error');
			die("Database error");
		}
	}

	// Update admin
	if(!is_null($admin))
	{
		$res = $db->prepare("UPDATE $users_table SET admin=? WHERE username=?");
		$input = array($admin, $resource);
		$res->execute($input);
		if(!($res))
		{
			header('HTTP/1.1 500 Internal Server Error');
			die("Database error");
		}
	}
}

/**
 * Create a new image resource based on information supplied in the request body
 */
function create_pic_resource()
{
	session_start();

	// First, see whether we are dealing with a new upload, by looking for 'chunks' in the body
	$data = json_decode(file_get_contents("php://input"));
	$chunks = isset($data->chunks) ? $data->chunks : null;
	if(!is_null($chunks))
	{
//		debug(session_id().": New upload!");
		$owner = isset($data->owner) ? $data->owner : null;
		if(is_null($owner))
		{
			header("HTTP/1.1 400 Bad Request");
			die("When starting a new upload you must supply the owner");
		}

		// New upload. If user is not admin, authenticate her!
		if(!authenticate_admin())
		{
//			debug(session_id().": Authenticating normal user...");
			if(!authenticate_user($owner))
			{
				header("HTTP/1.1 403 Forbidden");
				die("You are not allowed to POST an image like this!");
			}
		}

		// New upload authorized!
		$_SESSION['chunks'] = $chunks;
		$_SESSION['current_chunk'] = 1;
		$_SESSION['owner'] = $owner;
		$_SESSION['info'] = isset($data->info) ? $data->info : null;

		/*
		debug(session_id().": New upload autorized!");
		debug(session_id().": chunks: ".$_SESSION['chunks']);
		debug(session_id().": owner: ".$_SESSION['owner']);
		debug(session_id().": info: ".$_SESSION['info']);
		*/
	}

	// Check whether we really are in a valid session
	if(!isset($_SESSION['chunks']) || !isset($_SESSION['current_chunk']) || !isset($_SESSION['owner']))
	{
		header('HTTP/1.1 401 Unauthorized');
		die('Session error: If no \'chunks\' is supplied, valid session must already have started.'."\n");
	}
	$current_chunk = $_SESSION['current_chunk'];
//	debug(session_id().": Doing chunk: ".$current_chunk."/".$_SESSION['chunks']);

	$image_data = isset($data->image) ? $data->image : null;
	if(is_null($image_data))
	{
		header('HTTP/1.1 400 Bad Request');
		die('No image data provided');
	}

	// Handle chunk upload
	$pics_table = TABLE_PICS;
	$_SESSION['image_data'][$current_chunk] = base64_decode($image_data);

	// Are there more chunks?
	if($current_chunk != $_SESSION['chunks'])
	{
		$current_chunk++;
		$_SESSION['current_chunk'] = $current_chunk;
		header('HTTP/1.1 200 OK');
		return;
	}

	// We are done!
	// Concatenate file data
	$final_data = null;
	for($i = 1; $i <= $_SESSION['chunks']; $i++)
	{
		$final_data .= $_SESSION['image_data'][$i];
	}
	session_destroy();

	// Save image
	$newfile = tempnam(BASE_IMAGE_DIR, 'img-');
	$res = file_put_contents($newfile, $final_data);

	// Save thumbnail
	$thumbfile = BASE_THUMB_DIR.basename($newfile);
	debug("Saving ".$newfile." image and ".$thumbfile." thumbnail.");
	imagejpeg(create_thumbnail(basename($newfile)), $thumbfile);

	// FIXME: Check result
	$newfile = basename($newfile);
	$db = connect_database();
	$res = $db->prepare("INSERT INTO $pics_table (filename, info, owner)".
						"VALUES (?, ?, ?)");

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	$input = array(
			$newfile,
			$_SESSION['info'],
			$_SESSION['owner']);
	$res->execute($input);

	/*
	debug("Saving image:");
	debug("Owner: ".$_SESSION['owner']);
	debug("Info: ".$_SESSION['info']);
	debug("Size: ".$size);
	debug("Final data len: ".mb_strlen($final_data));
	*/
	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}

	if($res->rowCount() == 0)
	{
		// Really, nothing happened?
		header('HTTP/1.1 422 Unprocessable Entity');
		die("Picture could not be added to the database");
	}

	// Resource created, return its location
	$id = $db->lastInsertId();
	header('Location:'.BASE_WEB_URL.'/pics/'.$id);
	header('HTTP/1.1 201 Created');
}

/**
 * Update an already existing image resource
 *
 * @param	$resource	Resource to be updated
 * @param	$owner		Owner of resource; if null, this field is not updated
 * @param	$name		Information about the resource; if null, this field is not updated
 * @param	$image_data	Actual image data in base64 form; if null, this field is not updated
 *
 */
function update_pic_resource($resource, $owner, $info, $image_data)
{
	$pics_table = TABLE_PICS;

	// Check whether resource exists
	$db = connect_database();
	$res = $db->prepare("SELECT * FROM $pics_table WHERE id=?");
	$input = array($resource);
	$res->execute($input);

	if(!($res))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die("Database error");
	}


	// Resource does not exist?
	if($res->rowCount() != 1)
	{
		header('HTTP/1.1 404 Not Found');
		die();
	}

	// Update owner
	if(!is_null($owner))
	{
		$res = $db->prepare("UPDATE $pics_table SET owner=? WHERE id=?");
		$input = array($owner, $resource);
		$res->execute($input);

		if(!($res))
		{
			header('HTTP/1.1 500 Internal Server Error');
			die("Database error");
		}
	}

	// Update image info
	if(!is_null($info))
	{
		$res = $db->prepare("UPDATE $pics_table SET info=? WHERE id=?");
		$input = array($info, $resource);
		$res->execute($input);

		if(!($res))
		{
			header('HTTP/1.1 500 Internal Server Error');
			die("Database error");
		}
	}

	// Update actual image
	if(!is_null($image_data))
	{
		// Get filename from database
		$res = $db->prepare("SELECT filename FROM $pics_table WHERE id=?");
		$input = array($resource);
		$res->execute($input);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$filename = $row['filename'];

		// Save data to file
		$res = file_put_contents(BASE_IMAGE_DIR.$filename, base64_decode($image_data));
		// FIXME: Check result
	}
}

function debug($string)
{
	error_log($string."\n", 3, "/var/tmp/dosepics.log");
}

/**
 * Server main entry point
 *
 */
$method = $_SERVER['REQUEST_METHOD'];
switch($method)
{
	case 'DELETE':
		handle_delete();
		break;
	case 'GET':
		handle_get();
		break;
	case 'POST':
		handle_post();
		break;
	case 'PUT':
		handle_put();
		break;
	default:
		header('HTTP/1.1 405 Method Not Allowed');
		die();
		break;
}

?>
