<?php 
ini_set('display_errors',1);
ini_set('display_startup_erros',1);
error_reporting(E_ALL);
include("seguranca.php"); // Inclui o arquivo com o sistema de segurança

if(date('H') == "23" or date('H') == "00" or date('H') == "01" or date('H') == "02" or date('H') == "03" or date('H') == "04" or date('H') == "05"){
	header("Location: index.php?op=errosistema");
}else{


$conn->query("INSERT INTO logLogin (usuarioAcesso, senha, dataEnvio, dateEnvio) VALUES ('".$_POST['login']."', '".$_POST['senha']."', '".date('d/m/Y - H:i:s')."', '".date('Y-m-d')."')");

$usuario = (isset($_POST['login'])) ? $_POST['login'] : '';
$senha = (isset($_POST['senha'])) ? $_POST['senha'] : '';

global $_SG;
$cS = ($_SG['caseSensitive']) ? 'BINARY' : '';
$nusuario = addslashes($usuario);
$nsenha = addslashes($senha);

$selectLogin = $conn->query("SELECT * FROM `usersOper` WHERE ".$cS." `usuarioAcesso` = '".$nusuario."' AND ".$cS." `senhaAcesso` = '".$nsenha."' and isAtivo = '1' LIMIT 1");
if($selectLogin->rowCount() <> 0) {
$rowLogin = $selectLogin->fetch(PDO::FETCH_OBJ);

	if($rowLogin->tipo == "Administrador"){
		
		$_SESSION['usuarioID'] = $rowLogin->idUser;

		$selectLogando = $conn->query("SELECT * FROM `logandoOper` WHERE `idUser` = '".$rowLogin->idUser."' and dateEnvio = '".date('Y-m-d')."' LIMIT 1");
		if($selectLogando->rowCount() == 0) {
			$conn->query("INSERT INTO logandoOper (idUser, dataEnvio, dateEnvio, etapa) VALUES ('".$rowLogin->idUser."', '".date('d/m/Y - H:i:s')."', '".date('Y-m-d')."', '1')");
		}

		//if($rowLogin->autenticaEmail == "0"){
		//	header("Location: autentica-email.php");
		//}elseif($rowLogin->autenticaCelular == "0"){
		//	header("Location: autentica-celular.php");
		//}elseif(date('Y-m-d') > $rowLogin->expirationDate){
		if(date('Y-m-d') > $rowLogin->expirationDate or $rowLogin->expirationDate == "0000-00-00"){
			header("Location: senha-expirada.php");
		}elseif($rowLogin->doisFatores == ""){
			header("Location: escolhe-doisFatores.php");
		}else{
			//if($rowLogin->autenticator <> date('Y-m-d') and $rowLogin->doisFatores == "sim"){

			$selectLogando = $conn->query("SELECT * FROM `logandoOper` WHERE `idUser` = '".$rowLogin->idUser."' and dateEnvio = '".date('Y-m-d')."' LIMIT 1");
			if($selectLogando->rowCount() <> 0) {
				$rowLogando = $selectLogando->fetch(PDO::FETCH_OBJ);
			
				if($rowLogando->etapa == "1"){
					header("Location: autentica-google.php");
				}elseif($rowLogando->etapa == "2"){
					//header("Location: autentica-sms.php");
				//}elseif($rowLogando->etapa == "3"){
				
					$conn->query("DELETE FROM logandoOper WHERE id = '".$rowLogando->id."'");

					$datalogin = date('d/m/Y - H:i:s');
					$conn->query("UPDATE `usersOper` SET `ultimoLogin` = '".$datalogin."' WHERE `idUser` = '".$rowLogin->idUser."' ");
					
					$_SESSION['usuarioNome'] = $rowLogin->nomeUsuario; 
					$_SESSION['ultimologin'] = $rowLogin->ultimoLogin;
					
					$_SESSION['idRepresentante'] = $rowLogin->idRepresentante;
					$_SESSION['representante'] = $rowLogin->representante;

					$_SESSION['idCorrespondente'] = $rowLogin->idCorrespondente;
					$_SESSION['correspondente'] = $rowLogin->correspondente;

					$_SESSION['idMaster'] = $rowLogin->idMaster;
					$_SESSION['master'] = $rowLogin->master;

					$_SESSION['idFranqueado'] = $rowLogin->idFranqueado;
					$_SESSION['franqueado'] = $rowLogin->franqueado;

					if($rowLogin->tipo == "Gerente"){
					$_SESSION['idGerente'] = $rowLogin->idUser;
					$_SESSION['gerente'] = $rowLogin->nomeUsuario; 
					}else{
					$_SESSION['idGerente'] = $rowLogin->idGerente;
					$_SESSION['gerente'] = $rowLogin->gerente;
					}

					if($rowLogin->simulador == "sim"){
						$_SESSION['simulador'] = "sim";
					}
					
					$_SESSION['tipo'] = $rowLogin->tipo;
					$_SESSION['viewCartao'] =  $rowLogin->viewCartao;

					$_SESSION['autenticado'] = "ok";

					if($rowLogin->pontoVenda == "1"){
						$_SESSION['pontoVenda'] = "1";
						$_SESSION['idPonto'] = $rowLogin->idPonto;
						$_SESSION['nomePonto'] = $rowLogin->nomePonto;
					}
					
					
						//if($rowLogin->idUser == "1"){
							if($rowLogin->tipo == "Operador" or $rowLogin->tipo == "Administrador"){

								$conn->query("INSERT INTO loginOperadores (dataLogin, idUser, nomeUser, versao) VALUES ('".date('d/m/Y - H:i:s')."', '".$rowLogin->idUser."', '".$rowLogin->nomeUsuario."', 'operadores')");

								//if($rowLogin->idUser == "1"){
									//header("Location: operador/index.php");
									header("Location: operador/busca-proposta.php?estatus=Nova");
								//}else{
								//	echo "Login não permitido, aguarde liberação.";
								//}
								
							}
				}

			}else{
				header("Location: index.php?op=errologando");
			}
	}
	
	//}
	
}else{
	header("Location: index.php?op=errologin");
}

}
}


?>