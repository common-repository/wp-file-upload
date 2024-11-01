<?php
if ( !defined("ABSWPFILEUPLOAD_DIR") ) DEFINE("ABSWPFILEUPLOAD_DIR", dirname(__FILE__).'/');
if ( !defined("WFU_AUTOLOADER_PHP50600") ) DEFINE("WFU_AUTOLOADER_PHP50600", 'vendor/modules/php5.6/autoload.php');
include_once( ABSWPFILEUPLOAD_DIR.'lib/wfu_functions.php' );
include_once( ABSWPFILEUPLOAD_DIR.'lib/wfu_security.php' );
$handler = (isset($_POST['handler']) ? $_POST['handler'] : (isset($_GET['handler']) ? $_GET['handler'] : '-1'));
$session_legacy = (isset($_POST['session_legacy']) ? $_POST['session_legacy'] : (isset($_GET['session_legacy']) ? $_GET['session_legacy'] : ''));
$dboption_base = (isset($_POST['dboption_base']) ? $_POST['dboption_base'] : (isset($_GET['dboption_base']) ? $_GET['dboption_base'] : '-1'));
$dboption_useold = (isset($_POST['dboption_useold']) ? $_POST['dboption_useold'] : (isset($_GET['dboption_useold']) ? $_GET['dboption_useold'] : ''));
$wfu_cookie = (isset($_POST['wfu_cookie']) ? $_POST['wfu_cookie'] : (isset($_GET['wfu_cookie']) ? $_GET['wfu_cookie'] : ''));
if ( $handler == '-1' || $session_legacy == '' || $dboption_base == '-1' || $dboption_useold == '' || $wfu_cookie == '' ) die();
else {
	$GLOBALS["wfu_user_state_handler"] = wfu_sanitize_code($handler);
	$GLOBALS["WFU_GLOBALS"]["WFU_US_SESSION_LEGACY"] = array( "", "", "", ( $session_legacy == '1' ? 'true' : 'false' ), "", true );
	$GLOBALS["WFU_GLOBALS"]["WFU_US_DBOPTION_BASE"] = array( "", "", "", wfu_sanitize_code($dboption_base), "", true );
	$GLOBALS["WFU_GLOBALS"]["WFU_US_DBOPTION_USEOLD"] = array( "", "", "", ( $dboption_useold == '1' ? 'true' : 'false' ), "", true );
	if ( !defined("WPFILEUPLOAD_COOKIE") ) DEFINE("WPFILEUPLOAD_COOKIE", wfu_sanitize_tag($wfu_cookie));
	wfu_download_file();
}

/**
 * Download a File.
 *
 * This function causes a file to be downloaded.
 *
 * @global string $wfu_user_state_handler The user state handler.
 */
function wfu_download_file() {
	global $wfu_user_state_handler;
	$file_code = (isset($_POST['file']) ? $_POST['file'] : (isset($_GET['file']) ? $_GET['file'] : ''));
	$ticket = (isset($_POST['ticket']) ? $_POST['ticket'] : (isset($_GET['ticket']) ? $_GET['ticket'] : ''));
	if ( $file_code == '' || $ticket == '' ) die();
	
	wfu_initialize_user_state();
	
	$ticket = wfu_sanitize_code($ticket);	
	$file_code = wfu_sanitize_code($file_code);
	//if download ticket does not exist or is expired die
	if ( !WFU_USVAR_exists_downloader('wfu_download_ticket_'.$ticket) || time() > WFU_USVAR_downloader('wfu_download_ticket_'.$ticket) ) {
		WFU_USVAR_unset_downloader('wfu_download_ticket_'.$ticket);
		WFU_USVAR_unset_downloader('wfu_storage_'.$file_code);
		wfu_update_download_status($ticket, 'failed');
		die();
	}
	//destroy ticket so it cannot be used again
	WFU_USVAR_unset_downloader('wfu_download_ticket_'.$ticket);
	
	//if file_code starts with exportdata, then this is a request for export of
	//uploaded file data, so disposition_name wont be the filename of the file
	//but wfu_export.csv; also set flag to delete file after download operation
	if ( substr($file_code, 0, 10) == "exportdata" ) {
		$file_code = substr($file_code, 10);
		//$filepath = wfu_get_filepath_from_safe($file_code);
		$filepath = WFU_USVAR_downloader('wfu_storage_'.$file_code);
		//validate the file path to avoid directory traversals that could lead
		//to deletion of the wrong file
		if ( !wfu_validate_storage_filepath("exportdata", $filepath) ) die();
		$disposition_name = "wfu_export.csv";
		$delete_file = true;
	}
	//if file_code starts with debuglog, then this is a request for download of
	//debug_log.txt
	elseif ( substr($file_code, 0, 8) == "debuglog" ) {
		$file_code = substr($file_code, 8);
		//$filepath = wfu_get_filepath_from_safe($file_code);
		$filepath = WFU_USVAR_downloader('wfu_storage_'.$file_code);
		$disposition_name = wfu_basename($filepath);
		$delete_file = false;
	}
	else {
		//$filepath = wfu_get_filepath_from_safe($file_code);
		$filepath = WFU_USVAR_downloader('wfu_storage_'.$file_code);
		if ( $filepath === false ) {
			WFU_USVAR_unset_downloader('wfu_storage_'.$file_code);
			wfu_update_download_status($ticket, 'failed');
			die();
		}
		$filepath = wfu_flatten_path($filepath);
		if ( substr($filepath, 0, 1) == "/" ) $filepath = substr($filepath, 1);
		$filepath = ( substr($filepath, 0, 6) == 'ftp://' || substr($filepath, 0, 7) == 'ftps://' || substr($filepath, 0, 7) == 'sftp://' ? $filepath : WFU_USVAR_downloader('wfu_ABSPATH').$filepath );
		$disposition_name = wfu_basename($filepath);
		$delete_file = false;
	}
	//destroy file code as it is no longer needed
	WFU_USVAR_unset_downloader('wfu_storage_'.$file_code);
	//check that file exists
	if ( !wfu_file_exists_for_downloader($filepath) ) {
		wfu_update_download_status($ticket, 'failed');
		die('<script language="javascript">alert("'.( WFU_USVAR_exists_downloader('wfu_browser_downloadfile_notexist') ? WFU_USVAR_downloader('wfu_browser_downloadfile_notexist') : 'File does not exist!' ).'");</script>');
	}

	$open_session = false;
	@set_time_limit(0); // disable the time limit for this script
	$fsize = wfu_filesize_for_downloader($filepath);
	if ( $fd = wfu_fopen_for_downloader($filepath, "rb") ) {
		$open_session = ( ( $wfu_user_state_handler == "session" || $wfu_user_state_handler == "" ) && ( function_exists("session_status") ? ( PHP_SESSION_ACTIVE !== session_status() ) : ( empty(session_id()) ) ) );
		if ( $open_session ) session_start();
		header('Content-Type: application/octet-stream');
		header("Content-Disposition: attachment; filename=\"".$disposition_name."\"");
		header('Content-Transfer-Encoding: binary');
		header('Connection: Keep-Alive');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header("Content-length: $fsize");
		$failed = false;
		while( !feof($fd) ) {
			$buffer = @fread($fd, 1024*8);
			echo $buffer;
			ob_flush();
			flush();
			if ( connection_status() != 0 ) {
				$failed = true;
				break;
			}
		}
		fclose ($fd);
	}
	else $failed = true;
	
	if ( $delete_file ) wfu_unlink_for_downloader($filepath);
	
	if ( !$failed ) {
		wfu_update_download_status($ticket, 'downloaded');
		if ( $open_session ) session_write_close();
		die();
	}
	else {
		wfu_update_download_status($ticket, 'failed');
		if ( $open_session ) session_write_close();
		die('<script type="text/javascript">alert("'.( WFU_USVAR_exists_downloader('wfu_browser_downloadfile_failed') ? WFU_USVAR_downloader('wfu_browser_downloadfile_failed') : 'Could not download file!' ).'");</script>');
	}
}

/**
 * Update Download Status.
 *
 * Stores in user state the new download status.
 *
 * @param string $ticket The download ticket.
 * @param string $new_status The new download status.
 */
function wfu_update_download_status($ticket, $new_status) {
	require_once WFU_USVAR_downloader('wfu_ABSPATH').'wp-load.php';
	WFU_USVAR_store('wfu_download_status_'.$ticket, $new_status);
}

/**
 * Check User State Variable For Existence.
 *
 * Checks whether a user state variable exists.
 *
 * @global string $wfu_user_state_handler The user state handler.
 *
 * @param mixed $var The user state variable to check.
 * @return bool True if it exists, false otherwise.
 */
function WFU_USVAR_exists_downloader($var) {
	global $wfu_user_state_handler;
	if ( $wfu_user_state_handler == "dboption" && WFU_VAR("WFU_US_DBOPTION_BASE") == "cookies" ) return isset($_COOKIE[$var]);
	else return WFU_USVAR_exists_session($var);
}

/**
 * Get User State Variable.
 *
 * Returns the value of a user state variable. The variable needs to exist.
 *
 * @global string $wfu_user_state_handler The user state handler.
 *
 * @param mixed $var The user state variable to read.
 * @return mixed The variable value.
 */
function WFU_USVAR_downloader($var) {
	global $wfu_user_state_handler;
	if ( $wfu_user_state_handler == "dboption" && WFU_VAR("WFU_US_DBOPTION_BASE") == "cookies" ) return $_COOKIE[$var];
	else return WFU_USVAR_session($var);
}

/**
 * Unset User State Variable.
 *
 * Unsets a user state variable.
 *
 * @global string $wfu_user_state_handler The user state handler.
 *
 * @param mixed $var The user state variable to unset.
 */
function WFU_USVAR_unset_downloader($var) {
	global $wfu_user_state_handler;
	if ( $wfu_user_state_handler == "session" || $wfu_user_state_handler == "" ) WFU_USVAR_unset_session($var);
}

/**
 * Check for File Existence.
 *
 * Checks whether a file exists.
 *
 * @param string $filepath The full path of the file to check.
 * @return bool True if the file exists, false otherwise.
 */
function wfu_file_exists_for_downloader($filepath) {
	if ( substr($filepath, 0, 7) != "sftp://" ) return file_exists($filepath);
	$ret = false;
	$ftpinfo = wfu_decode_ftpurl($filepath);
	if ( $ftpinfo["error"] ) return $ret;
	$data = $ftpinfo["data"];
	{
		$conn = @ssh2_connect($data["ftpdomain"], $data["port"]);
		if ( $conn && @ssh2_auth_password($conn, $data["username"], $data["password"]) ) {
			$sftp = @ssh2_sftp($conn);
			$ret = ( $sftp && @file_exists("ssh2.sftp://".intval($sftp).$data["filepath"]) );
		}
	}
	
	return $ret;
}

/**
 * Get File Size.
 *
 * Return the size of a file.
 *
 * @param string $filepath The full path of the file.
 * @return int|bool The size of the file on success, false on failure.
 */
function wfu_filesize_for_downloader($filepath) {
	if ( substr($filepath, 0, 7) != "sftp://" ) return filesize($filepath);
	$ret = false;
	$ftpinfo = wfu_decode_ftpurl($filepath);
	if ( $ftpinfo["error"] ) return $ret;
	$data = $ftpinfo["data"];
	{
		$conn = @ssh2_connect($data["ftpdomain"], $data["port"]);
		if ( $conn && @ssh2_auth_password($conn, $data["username"], $data["password"]) ) {
			$sftp = @ssh2_sftp($conn);
			if ( $sftp ) $ret = @filesize("ssh2.sftp://".intval($sftp).$data["filepath"]);
		}
	}
	
	return $ret;
}

/**
 * Get File Handler.
 *
 * Returns a file handler for reading the contents of the file. If the file is
 * in an FTP location, then it is first copied in a memory stream and the
 * handler of the memory stream is returned.
 *
 * @param string $filepath The full path of the file.
 * @param string $mode The reading mode.
 * @return resource|bool The file handler on success, false on failure.
 */
function wfu_fopen_for_downloader($filepath, $mode) {
	if ( substr($filepath, 0, 7) != "sftp://" ) return @fopen($filepath, $mode);
	$ret = false;
	$ftpinfo = wfu_decode_ftpurl($filepath);
	if ( $ftpinfo["error"] ) return $ret;
	$data = $ftpinfo["data"];
	{
		$conn = @ssh2_connect($data["ftpdomain"], $data["port"]);
		if ( $conn && @ssh2_auth_password($conn, $data["username"], $data["password"]) ) {
			$sftp = @ssh2_sftp($conn);
			if ( $sftp ) {
				//$ret = @fopen("ssh2.sftp://".intval($sftp).$data["filepath"], $mode);
				$contents = @file_get_contents("ssh2.sftp://".intval($sftp).$data["filepath"]);
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $contents);
				rewind($stream);
				$ret = $stream;
			}
		}
	}
	
	return $ret;
}

/**
 * Delete a File.
 *
 * Deletes a file. It also supports FTP locations.
 *
 * @param string $filepath The full file path of the file to delete.
 * @return bool True on success, false otherwise.
 */
function wfu_unlink_for_downloader($filepath) {
	if ( substr($filepath, 0, 7) != "sftp://" ) return @unlink($filepath);
	$ret = false;
	$ftpinfo = wfu_decode_ftpurl($filepath);
	if ( $ftpinfo["error"] ) return $ret;
	$data = $ftpinfo["data"];
	{
		$conn = @ssh2_connect($data["ftpdomain"], $data["port"]);
		if ( $conn && @ssh2_auth_password($conn, $data["username"], $data["password"]) ) {
			$sftp = @ssh2_sftp($conn);
			if ( $sftp ) $ret = @unlink("ssh2.sftp://".intval($sftp).$data["filepath"]);
		}
	}
	
	return $ret;
}

/**
 * Validate Storage Filepath.
 *
 * It validates the filepath that was retrieved from user state storage. For the
 * moment it works only when the downloaded file is an export of the plugin's
 * data. It validates that the retrieved filepath is the PHP temp path.
 *
 * @since 4.24.12
 *
 * @param string $code The download code.
 * @param string $filepath The retrieved full file path.
 * @return bool True if validation has passed, false otherwise.
 */
function wfu_validate_storage_filepath($code, $filepath) {
	$result = false;
	if ( $code === "exportdata" ) {
		$onlypath = wfu_basedir($filepath);
		if ( substr($onlypath, -1) !== '/' ) $onlypath .= '/';
		$exportpath = sys_get_temp_dir();
		if ( substr($exportpath, -1) !== '/' ) $exportpath .= '/';
		$result = ( $onlypath === $exportpath );
	}
	return $result;
}