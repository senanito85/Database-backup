<?php
/**
 *
 * php Folder Backups
 *
 * php script to backup a folder
 *
 *<p><b>Changelog:</b></p>
 * - v1.0.0		: 
 * - v1.1.0		: 2017-11-28 : Add standard copy functionality
 *
 * @author John Comerford <johnc@optionsystems.com.au>
 * @package System Administrator
 * @subpackage Core
 * @copyright John Comerford - 2013
 * @license All rights reserved
 * @version v1.1.0
 */



$origIncludePath = ini_get('include_path');
ini_set('include_path',"{$origIncludePath}" . PATH_SEPARATOR ."./pear");
require_once('phpmailer/class.phpmailer.php');

//clude('Mail.php');
//clude('Mail/mime.php');
        
if (isset($argv[1])) {
	$configId = $argv[1];
	$logId = $configId;
}
else {
	$configId = "config";
	$logId = "backup";
}

error_reporting(E_ERROR);
$configFile = file_get_contents("{$configId}.json");
$config =json_decode($configFile,true);





if (!isset($config['logDir'])) $config['logDir'] = "/tmp";
if (!isset($config['appendLogs'])) $config['appendLogs'] = false;

if (substr($config['logDir'],-1,1) == "/")
	$config['logDir'] = substr($config['logDir'],0,-1);

$_SESSION['logFile'] = $config['logDir'] . "/{$logId}." . date("d") . ".log";
$_SESSION['logFile.1'] = $config['logDir'] . "/{$logId}." . date("d") . ".det";
$_SESSION['datFile'] = "{$logId}.dat";

if ($config['appendLogs'] == false) {
	unlink($_SESSION['logFile']);
	unlink($_SESSION['logFile.1']);
}

logit("Starting Backup");
$_SESSION['warnings'] = 0;
$_SESSION['Transfered'] = 0;
$_SESSION['targetType'] = $config['targetType'];


logit("Summary Log: {$_SESSION['logFile']}");
logit("Detailed Log: {$_SESSION['logFile']}");

if (file_exists($_SESSION['datFile'])) {
	$unS = file_get_contents($_SESSION['datFile']);
	$_SESSION['data'] = unserialize($unS);
	logit('Reading runtime settings:');
	foreach ($_SESSION['data'] as $key => $val) {
		logit("{$key} => {$val}");
	}
}

if ($config['targetType'] == "ftp") {
    $ok = checkMandatoryValue($config, array('backupTitle','sendEmails','targetDir','targetType','targetHost','targetUser','targetPass','sources'));
} else $ok = checkMandatoryValue($config, array('backupTitle','sendEmails','targetDir','targetType','sources'));
if (count($config['sources']) < 1) {
	$ok = false;
	logit("** No backup sources defined...");
}

if ($config['sendEmails']) {
	$ok = checkMandatoryValue($config, array('SMTPHost','SMTPUseAuth','EmailFrom','EmailTo'));
	if (!$ok) {
		logit("** SMTP configuration Error...");
		backupFailed();
	} else {
		$config = setDefaultValues($config, array("SMTPPort" => 25));
	}
} // if ($config['sendEmails']) 

if ($config['SMTPUseAuth']) {
	$ok = checkMandatoryValue($config, array('SMTPUser','SMTPPass'));
	if (!$ok) {
		logit("** SMTP configuration Error...");
	}
} // if ($config['sendEmails'])



if ($ok == false) {
	backupFailed();
} // if ($ok == false)



if ($config['targetType'] == "ftp")
    $config = setDefaultValues($config, array("transferMode" => "FTP_BINARY"));
    
    
$config = setDefaultValues($config, array("archiveType" => "simple",
										  "postSciptOnError" => true));
$_SESSION['config'] = $config;


if (isset($config['preScript'])) {
	logit("Executing preScript: {$config['preScript']}");
	$res = array();
	exec($config['preScript'],$res);
	foreach ($res as $oLine) {
		logit($oLine);
	}
} // if (isset($config['preScript'])) 




if ($config['targetType'] == "ftp") {
    logit('Establishing FTP Connection...');
    $connId = ftp_connect($config['targetHost']);
    // login with username and password
    $loginResult = ftp_login($connId, $config['targetUser'], $config['targetPass']);
    // check connection
    if ((!$connId) || (!$loginResult)) {
    	logit("FTP connection has failed!");
    	backupFailed();
    } else {
    	logit("Connection established.");
    }


} // if (config['targetType'] == "ftp")
else {
    logit('Preparing to copy files...');
}

logit('Starting backup...');
foreach ($config['sources'] as $source) {
	if (isset($source['source'])) {
		logit("----------- Source: {$source['source']} -----------");
		$_SESSION['sourceTrans'] = 0;
		
		if (!is_dir($source['source'])) {
			$_SESSION['warnings']++;
			logit('** (warning) Source directory does not exist, skipping it...');
			continue;
		}
		$source = setDefaultValues($source, array("targetDir" => $config['targetDir'],
												  "archiveType" => $config['archiveType'],
												  "recurrsive" => true
										));

		if (substr($source['targetDir'],-1,1) == "/")
			$source['targetDir'] = substr($source['targetDir'],0,-1);

	    if ($config['targetType'] == "ftp") $connId = ftpReconnect($connId);
		else $connId = null;
	    
		if (!backupMakeDirectory($connId, $source['targetDir'])) {
		    logit("** 1.1 (warning) There was a problem while creating target {$source['targetDir']}...");
			$_SESSION['warnings']++;
			continue;
		}

		logit("Archice type is: {$_SESSION['config']['archiveType']}");
		
		if ($_SESSION['config']['archiveType'] == "week") {
			$source['targetDir'] = $source['targetDir'] . DIRECTORY_SEPARATOR . date('D');
			if (!backupMakeDirectory($connId, $source['targetDir'])) {
				logit("** 1.2 (warning) There was a problem while creating target {$source['targetDir']}...");
				$_SESSION['warnings']++;
				continue;
			}
				
			
		} // if ($_SESSION['config']['archiveType'] == "week")
		else if ($_SESSION['config']['archiveType'] == 'seq') {
			if (!isset($_SESSION['data']['backupSeq'])) $_SESSION['data']['backupSeq'] = 0;
			$_SESSION['data']['backupSeq'] ++;
			
			if (isset($_SESSION['config']['archiveMax'])) {
				logit("Archive max set to: {$_SESSION['config']['archiveMax']} Currently:  {$_SESSION['data']['backupSeq']}");
				if ($_SESSION['data']['backupSeq'] > $_SESSION['config']['archiveMax']) 
					$_SESSION['data']['backupSeq'] = 1;
			} // if (isset($_SESSION['config']['archiveMax'])
			logit("Backup Target seq: {$_SESSION['data']['backupSeq']}");
			
			putBackupData();
			$source['targetDir'] = $source['targetDir'] . DIRECTORY_SEPARATOR . $_SESSION['data']['backupSeq'];
			if (!backupMakeDirectory($connId, $source['targetDir'])) {
				logit("** 1.4 (warning) There was a problem while creating target {$source['targetDir']}...");
				$_SESSION['warnings']++;
				continue;
			}			
		} // else if ($_SESSION['config']['archiveType'] == 'seq') 
		
		$backTarget = $source['targetDir'] . $source['source'];  
		logit("Target Directory is {$backTarget}");
		if (!backupMakeDirectory($connId, $backTarget)) {
			logit("** 1.3 (warning) There was a problem while creating target {$backTarget}...");
			$_SESSION['warnings']++;
			continue;
		}
		
		if ($config['targetType'] == "ftp") $res = ftpPutAll($connId,$source['source'],$backTarget);
		else {
		    logit("Starting file copy...");
		    $res = cpPutAll($source['source'],$backTarget);
		}
		
		if (!$res['ok']) {
			logit("** (critical): {$res['msg']}");
			backupFailed();	
		} //else if (!$res['ok']);
		else {
			logit("{$res['msg']}");
		} // else 
	} else {
		$_SESSION['warnings']++;
		logit('Source directory not found in config file, skipping...'); 
	}
} // foreach ($config['sources'] as $source)


if ($config['targetType'] == "ftp") ftp_close($connId);

$sizeStr = "MB";
if ($_SESSION['transferred'] > 1048576) {
	$sizeStr = "MB";
	$_SESSION['transferred'] = $_SESSION['transferred'] / 1048576;
} else {
	$sizeStr = "KB";
	$_SESSION['transferred'] = $_SESSION['transferred'] / 1024;
}
$_SESSION['transferred']= round($_SESSION['transferred'], 2);


logit(">>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
logit("Backup Complete, {$_SESSION['transferred']}{$sizeStr} backed up. {$_SESSION['warnings']} warnings.");

$title = "Completed";

if ($_SESSION['warnings'] > 0) {
	$title .= " with {$_SESSION['warnings']} warnings";
	$logLevel = "1";	
} else $logLevel = "0";


doPostScript();

sendEmail($title,"The backup completed, a detailed log file is attached",$logLevel);



function backupFailed() {
	logit("--- Backup Failed ! ---");
	if (!$_SESSION['config']['postSciptOnError']) {
		logit("postScriptOnError is set to false, skipping Post Backup Script");
	} else doPostScript();
	sendEmail(" Failed","The backup failed, a detailed log file is attached","1");
	exit;
} // function backupFailed()


function doPostScript() {
	if (isset($_SESSION['config']['postScript'])) {
		logit("Executing postScript: {$_SESSION['config']['postScript']}");
		$res = array();
		exec($_SESSION['config']['postScript'],$res);
		foreach ($res as $oLine) {
			logit($oLine);
		}
	} // if (isset($config['preScript']))
} // function doPostScript()



function checkMandatoryValue($config,$keys) {
	$ok = true;
	logit("Checking Mandatory Settings...");
	foreach ($keys as $key) {
		if (!isset($config[$key])) {
			logit("** Missing Setting for: {$key}");
			$ok = false;
		} //if (!isset($config[$key]))
	    else logit("{$key} : {$config[$key]}",1);
	} // foreach ($keys as $key)
		
	if ($ok == false) 
		logit("Some mandatory settings are missing, cannot perform backup");
	else logit("Mandatory settings are OK...");
	return $ok;
} // function checkMandatoryValue($config,$keys)


function ftpCreateDir($connId,$dir) {
	
	if (!ftpIsDir($connId,$dir)) {
		if (ftp_mkdir($connId, $dir)) {
			logit("Successfully created target {$dir}",1);
			return true;
		} else {
			logit("** (warning) There was a problem while creating target {$dir}...");
			$_SESSION['warnings']++;
			return false;
		}
	} // if (!ftpIsDir($connId,$source['targetDir']))
	else return true;
	
} // function ftpCreateDir($connId,$dir)


function ftpIsDir($ftp, $dir)
{
	$pushd = ftp_pwd($ftp);

	if ($pushd !== false && @ftp_chdir($ftp, $dir))
	{
		ftp_chdir($ftp, $pushd);
		return true;
	}

	return false;
}


function backupMakeDirectory($connId,$dir) {
    if ($_SESSION['targetType'] == "ftp") return ftpMakeDirectory($connId, $dir);
    else {
        if (!file_exists($dir)) return mkdir($dir,0777, true);
        else {
            if (is_dir($dir)) return true;
            else return false;
        }
    }
} // function backupMakeDirectory


function ftpMakeDirectory($connId, $dir)
{
	// if directory already exists or can be immediately created return true
	if (ftpIsDir($connId, $dir) || @ftp_mkdir($connId, $dir)) return true;
	// otherwise recursively try to make the directory
	if (!ftpMakeDirectory($connId, dirname($dir))) return false;
	// final step to create the directory
	return ftp_mkdir($connId, $dir);
}


function cpPutAll( $src_dir, $dst_dir) {
    logit("Copying folder: {$src_dir}...",1);
    $res = array();
    $transferred = 0;
    $dir = opendir($src_dir);
    $numFiles = 0;
  
    if (!file_exists($dst_dir)) {
        if(!@@mkdir($dst_dir)) {
            $errors= error_get_last();
            Logit("Error: 2.1");
            $res['ok'] = false;
            $res['msg'] = "** Failed to create Directory: {$dst_dir}/{$file}";
            $res['msg'] .= "\n Error: {$errors['type']}";
            $res['msg'] .= "\n Error: {$errors['message']}";
            logit($res['msg']);
            return $res;
        } //  if(!@@mkdir($dst_dir))
    } else {
        if (!is_dir($dst_dir)) {
            Logit("Error: 2.2");
            $res['ok'] = false;
            $res['msg'] = "** Destination is not a folder: {$dst_dir}";
            logit($res['msg']);
            return $res;
        } // if (!is_dir($dst_dir))
    } // else
    
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($src_dir . '/' . $file) ) {
                cpPutAll($src_dir . '/' . $file,$dst_dir . '/' . $file);
            }
            else {
                
                if(!@copy($src_dir . '/' . $file,$dst_dir . '/' . $file))
                {
                    $errors= error_get_last();
                    Logit("Error: 2.3");
                    $res['ok'] = false;
                    $res['msg'] = "Failed to transfer file: {$src_dir}/{$file}";
                    $res['msg'] .= "\n Error: {$errors['type']}"; 
                    $res['msg'] .= "\n Error: {$errors['message']}";
                    logit($res['msg']);
                    return $res;
                } else {
                    $transferred +=filesize($dst_dir."/".$file);
                    $numFiles++;
                } // else 
                
                
            } // else 
        } //if (( $file != '.' ) && ( $file != '..' ))
    } // while(false !== ( $file = readdir($dir)) )
    closedir($dir);
    
    $_SESSION['transferred'] += $transferred;
    $_SESSION['sourceTrans'] += $transferred;
    logit("{$dst_dir}: {$numFiles} files copied...");
    $res['ok'] = true;
    $res['bytes'] = $_SESSION['sourceTrans'];
    $res['msg'] = "Copy complete, " . byteToDesc($_SESSION['sourceTrans']) . " copied...";
    return $res;
    
} // function cpPutAll( $src_dir, $dst_dir)


function ftpPutAll($conn_id, $src_dir, $dst_dir) {
	$d = dir($src_dir);
	$res = array();
	$transferred = 0;
	//echo ">>{$src_dir} \n";
	while($file = $d->read()) { // do this for each file in the directory
		if ($file != "." && $file != "..") { // to prevent an infinite loop
			if (is_dir($src_dir."/".$file)) { // do the following if it is a directory
				if (isset($_SESSION['config']['exclude'])) {
					if (in_array($src_dir."/".$file, $_SESSION['config']['exclude'])) {
						logit(" -- Skipping folder: {$src_dir}/{$file}....");
						continue;
					}
					
				}
				$chRes = 0;
				$chRes = ftp_chdir($conn_id, $dst_dir."/".$file); 
				
				/**if (!$chRes) {
					echo "--- retry chdir {$dst_dir}/{$file} " . ftp_chdir($conn_id, $dst_dir."/".$file) . " ---\n";
					sleep(5);
					$chRes = ftp_chdir($conn_id, $dst_dir."/".$file);
					logit ('** current folder: ' . ftp_pwd($conn_id) . "\n");
					echo "--- chRes {$chRes} Trans: {$transferred}\n";
				} **/
				
				
				//if (trim($chRes) == "") $chRes = 1;
				
				if (!$chRes) {
				/**	logit('** change directory failed for:' .  $dst_dir."/".$file);
					logit ('** current folder: ' . ftp_pwd($conn_id));
					//echo "--- " . ftp_chdir($conn_id, $dst_dir."/".$file) . " ---\n";
					**/
					if (!ftp_mkdir($conn_id, $dst_dir."/".$file)) // create directories that do not yet exist
					{
						$res['ok'] = false;
						$res['msg'] = "** Failed to create Directory: {$dst_dir}/{$file}";
						logit($res['msg']);
						return $res;
					} // if (!ftp_mkdir($conn_id, $dst_dir."/".$file))
						
					
				}
				
				$mod = date("YmdHis", filemtime($src_dir));
				$modRes = ftp_raw($conn_id,'MDTM ' . $mod . " " . $dst_dir . "/.");
				ftpPutAll($conn_id, $src_dir."/".$file, $dst_dir."/".$file); // recursive part
			} else {
				if (!ftp_put($conn_id, $dst_dir."/".$file, $src_dir."/".$file, FTP_BINARY)) // put the files
				{
					$res['ok'] = false;
					$res['msg'] = "Failed to transfer file: {$src_dir}/{$file}";
					return $res;
				} //if (!ftp_put($conn_id, $dst_dir."/".$file, $src_dir."/".$file, FTP_BINARY))
				else {
					$fileSize = ftp_size($conn_id,$dst_dir."/".$file);
					
					$mod = date("YmdHis", filemtime($src_dir."/".$file));
				//	echo 'MDTM ' . $mod . " " . $dst_dir."/".$file ."\n";
					$modRes = ftp_raw($conn_id,'MDTM ' . $mod . " " . $dst_dir."/".$file);
				//	print_r($modRes);
					$transferred +=$fileSize;
				}
			}
		}
	}
	$d->close();


	$_SESSION['transferred'] += $transferred;
	$_SESSION['sourceTrans'] += $transferred;

	$res['ok'] = true;
	$res['bytes'] = $_SESSION['sourceTrans'];
	$res['msg'] = "Copy complete, " . byteToDesc($_SESSION['sourceTrans']) . " copied...";
	
	return $res;
	
}


function ftpReconnect($connId) {
	ftp_close($connId);
	$connId = ftp_connect($_SESSION['config']['targetHost']);
	// login with username and password
	$loginResult = ftp_login($connId,$_SESSION['config']['targetUser'], $_SESSION['config']['targetPass']);
	//ftp_pasv($connId, true);
	
	return $connId;
}

function logIt($text,$level=0) {
	$logText = sysMessage($text);
	// echo "{$logText} \n";
	writeToLog($_SESSION['logFile.1'],$logText);
	if ($level == 0) writeToLog($_SESSION['logFile'],$logText);;
	return true;
}






function setDefaultValues($config,$values) {
	logit("Checking default values...",1);
	foreach ($values as $key => $value) {
		if (!isset($config[$key])) {
			logit("Setting {$key} to {$value}",1);
			$config[$key] = $value;
		} else logit("Setting {$key} contained in configuration, default not set",1);
	} // foreach ($values as $key => $value)
	logit("Default values set...",1);
	return $config;
} // function setDefaultValues


function sendEmail($subject, $text,$attachLog="0",$noLog=false) {
	if (!$_SESSION['config']['sendEmails']) {
		print_r($_SESSION);
		logit("Emailing is switched off");
		return true;
	}
	
	
	$mail = new PHPMailer(true); // the true param means it will throw exceptions on errors, which we need to catch
	
	$mail->IsSMTP(); // telling the class to use SMTP
	
	try {
		$mail->Host       = $_SESSION['config']['SMTPHost']; // SMTP server
		$mail->SMTPDebug  = 0;                     // enables SMTP debug information (for testing)
		$mail->Port       = $_SESSION['config']['SMTPPort'];
		$mail->SetFrom($_SESSION['config']['EmailFrom'], 'Backup');
		$mail->MsgHTML($text);
		
		$mail->Subject =  "{$_SESSION['config']['backupTitle']} - {$subject} ";
		
		if ($_SESSION['config']['SMTPUseAuth']) {
			$mail->SMTPAuth   = true;                  // enable SMTP authentication
			$mail->Username   = $_SESSION['config']['SMTPUser']; // SMTP account username
			$mail->Password   = $_SESSION['config']['SMTPPass'];        // SMTP account password
		}
		
		$addresses = explode(",",$_SESSION['config']['EmailTo']);
		foreach ($addresses as $addr) {
			$mail->AddAddress($addr);
		}
		
		
		if ($attachLog == 0)
			$mail->AddAttachment($_SESSION['logFile'],"backup.log");
		elseif ($attachLog == 1)
			$mail->AddAttachment($_SESSION['logFile.1'],"backup.log");
		
		
		$mail->Send();
		if (!$noLog) logIt("Email Message Sent OK");
	} catch (phpmailerException $e) {
		if (!$noLog) {
			logIt("phpMailer reported an error sending email.");
			logIt($e->errorMessage()); //Pretty error messages from PHPMailer
		}
	} catch (Exception $e) {
		if (!$noLog) {
			logIt("php reported an error sending email.");
			logIt($e->getMessage()); //Boring error messages from anything else!
		}
	}
	
}



function sysMessage ($text) {
	return $text = date("y-m-d H:i:s") . " {$type}: {$text}\n";
}


function writeToLog($logFile,$text) {
	if (!$logHandle = fopen($logFile, 'a')) {
			echo ("Unable to open file {$logFile}");
			sendEmail("Critical Error","Unable to open file {$logFile}","",true);
			exit;
		}
	fwrite($logHandle,$text);
	fclose($logHandle);
}


function byteToDesc($bytes) {
	$sizeStr = "MB";
	if ($bytes > 1048576) {
		$sizeStr = "MB";
		$bytes = $bytes / 1048576;
	} else {
		$sizeStr = "KB";
		$bytes = $bytes / 1024;
	}
	$bytes= round($bytes, 2);
	return "{$bytes}{$sizeStr}";
	
}


function putBackupData() {
	if (isset($_SESSION['data'])) {
		file_put_contents($_SESSION['datFile'],serialize($_SESSION['data']));
	}
}
