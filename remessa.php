<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include "boleto.php";
include "funcoes.php";
require_once ('/home1/premold1/public_html/sispre/Includes/ConexaoSispre.php'); // conexão = $con

$json = '{"1":34,"2":35,"3":36,"4":809}';
$arrayBoletos = json_decode($json, true);

//$arrayBoletos = isset($_POST['boletos']) ? json_decode($_POST['boletos'], true) : "";
/////////////////////////////////////////////
/// DADOS DO HEADER DO ARQUIVO REMESSA (1A. LINHA)

$query = "select
empresas.razaosocial,
empresas.cnpj,
agencia,
agenciadigito,
conta,
contadigito,
date_format(now(), '%d%m%y') as datacriacaoremessa,
(select max(numerosequencial) from remessasdeboletos where conta_financeira is null) as sequencialarquivo
from contasfinanceiras
left join empresas on empresas.idempresa=contasfinanceiras.idempresa
left join remessasdeboletos on remessasdeboletos.conta_financeira=contasfinanceiras.idcontafinanceira
where idcontafinanceira=10
";

$result = mysqli_query($con, $query) or die(mysqli_error($con));

while ($reg = mysqli_fetch_assoc($result)) {
    $agenciaClienteSafra = str_pad($reg['agencia'], 4, "0", STR_PAD_LEFT) . str_pad($reg['agenciadigito'], 1, "0", STR_PAD_LEFT); // Agência cliente safra 99999. Exemplo: 11500
    $contaComDvClienteSafra = str_pad($reg['conta'], 8, "0", STR_PAD_LEFT) . str_pad($reg['contadigito'], 1, "0", STR_PAD_LEFT); //Conta cliente safra com DV 009999999. Exemplo: 000001234
    $idEmpresaBanco = $agenciaClienteSafra . $contaComDvClienteSafra; //identificacao da empresa no banco - Agência 99999 + Conta com DV 009999999. Exemplo: Agência 11500 + Conta com DV 000001234
    $razaoClienteSafra = $reg['razaosocial']; //razao social da empresa cliente safra
    $dataCriacaoArquivoRemessa = $reg['datacriacaoremessa']; //Data de criação do arquivo remessa - formato DDMMAA
    $sequencialArquivo = $reg['sequencialarquivo'] + 1; //Sequencial a partir de 001, a cada novo arquivo somar +1 (Ex: 001,002, 003)
    $sequencialRegistro = 1; //000001 - Número Sequencial do Registro do Arquivo
}


$header = str_repeat(" ", 400);
$header = setCampo(1, 1, " ", "0", $header); //0 - identificacao do registro header
$header = setCampo(2, 2, " ", "1", $header); //1 - identificacao do arquivo remessa
$header = setCampo(3, 9, " ", "REMESSA", $header); //REMESSA - identificacao do arquivo por extenso
$header = setCampo(10, 11, " ", "01", $header); //01 - código de identificação do serviço 
$header = setCampo(12, 19, " ", "COBRANCA", $header); //COBRANCA - identificação do serviço por extenso
$header = setCampo(20, 26, " ", "", $header); //deixar em branco
$header = setCampo(27, 40, " ", $idEmpresaBanco, $header); //ver declaração da variável $idEmpresaBanco
$header = setCampo(41, 46, " ", "", $header); //campo sem preenchimento
$header = setCampo(47, 76, " ", $razaoClienteSafra, $header); //razao social da empresa cliente safra
$header = setCampo(77, 79, " ", "422", $header); //422 - codigo de identificação do banco
$header = setCampo(80, 90, " ", "BANCO SAFRA", $header); //BANCO SAFRA - nome do banco por extenso
$header = setCampo(91, 94, " ", "", $header); // deixar em branco
$header = setCampo(95, 100, " ", $dataCriacaoArquivoRemessa, $header); //Data de criação do arquivo remessa - formato DDMMAA
$header = setCampo(101, 391, " ", "", $header); // deixar em branco
$header = setCampo(392, 394, "0", $sequencialArquivo, $header); //Sequencial a partir de 001, a cada novo arquivo somar +1 (Ex: 001,002, 003)
$header = setCampo(395, 400, "0", $sequencialRegistro, $header); //000001 - Número Sequencial do Registro do Arquivo

$remessaTxt = "";
$remessaTxt.="$header\n";

/////////////////////////////////////////////
/// DADOS DOS BOLETOS DO ARQUIVO REMESSA 1 LINHA PARA CADA BOLETO
//// ATENÇÃO: FALTA ARRUMAR ESSE SELECT E OS DADOS DE CADA DETALHE DO BOLETO. HEADER E TRAILLER JÁ ESTÃO "PRONTOS"

$filtroBoletos = " false ";
foreach ($arrayBoletos as $boleto) {
    $filtroBoletos.= " or idboleto=$boleto ";
}

$query = "select
nomeourazao,
pfoupj,
cpfoucnpj,
round(valorprincipal*100, 0) as valorprincipal
from boletos
left join clientes on clientes.idclientes=boletos.idcliente
where $filtroBoletos
";

$result = mysqli_query($con, $query) or die(mysqli_error($con));

$quantidadeTitulos = 0;
$valorTotalTitulos = 0;

while ($reg = mysqli_fetch_assoc($result)) {

    $tipoCpfOuCnpjClienteSafra = "02"; //01 = CPF ou 02 = CNPJ (do cliente safra)
    $cpfOuCnpjClienteSafra = "09512078000160"; // cpf ou cnpj cliente safra
    $campoUsoLivreEmpresa = ""; //Campos Alfanuméricos para uso livre da empresa (Banco processa e devolve no arquivo retorno)
    $nossoNumero = ""; //NOSSO NÚMERO - numeração de acordo com critérios da empresa. não pode ser zerado e que não pode ser repetido, ou seja, cada título deverá conter uma numeração única.
    $codigoBoleto = ""; //Identificação do Título na Empresa. Número adotado e controlado pela empresa para identificação do título
    $dataVenvimentoTitulo = ""; //data de vencimento titulo. formato DDMMAA
    $valorNominalTitulo = $reg['valorprincipal']; //Valor do Título com 2 Decimais (sem pontos e vírgulas)
    $dataEmissaoTitulo = ""; //Data de Emissão do Título. formato DDMMAA
    $jurosDeMoraDia = ""; //Juros de mora por dia em reais duas casas decimais (sem pontos e sem virgulas)
    $dataMulta = ""; //data a partir da qual a multa deve ser cobrada (vencimento + 1 dia no formato "ddmmaa") (vide nota 1)
    $percentualMulta = ""; //percentual referente à multa no formato 99v99, ex.: 2% preencher 0200 (vide nota 1)
    $dataLimiteDesconto = ""; //Data Limte para Desconto. Utilizar o formato DDMMAA
    $tipoCpfOuCnpjPagador = ""; //01 = CPF ou 02 = CNPJ do pagador
    $cpfOuCnpjPagador = ""; // cpf ou cnpj do pagador
    $nomePagador = $reg['nomeourazao']; //nome completo do pagador
    $enderecoPagador = ""; //endereço do Pagador
    $bairroPagador = ""; //bairro do pagador
    $cepPagador = ""; //cep do pagador
    $cidadePagador = ""; //cidade do pagador
    $estadoPagador = ""; //UF do estado do pagador
    $sequencialRegistro++; //Sequencial de registro a partir do Header (000001), no detalhe informar 000002, 000003, etc...
    $quantidadeTitulos++; // Quantidade de títulos gerados no arquivo. Ex: 5 títulos = preencher 00000005
    $valorTotalTitulos += $valorNominalTitulo; // Valor total dos títulos gerados no arquivo. Ex: total de R$ 350,88 = preencher 000000000035088


    $detalhe = str_repeat(" ", 400);
    $detalhe = setCampo(1, 1, " ", "1", $detalhe); // 1 - Identificação do Registro Transação
    $detalhe = setCampo(2, 3, " ", $tipoCpfOuCnpjClienteSafra, $detalhe); //01 = CPF ou 02 = CNPJ (do cliente safra)
    $detalhe = setCampo(4, 17, "0", $cpfOuCnpjClienteSafra, $detalhe); // cpf ou cnpj cliente safra
    $detalhe = setCampo(18, 31, " ", $idEmpresaBanco, $detalhe); ////identificacao da empresa no banco - Agência 99999 + Conta com DV 009999999. Exemplo: Agência 11500 + Conta com DV 000001234
    $detalhe = setCampo(32, 37, " ", "", $detalhe); //deixar em branco
    $detalhe = setCampo(38, 62, " ", $campoUsoLivreEmpresa, $detalhe); //Campos Alfanuméricos para uso livre da empresa (Banco processa e devolve no arquivo retorno)
    $detalhe = setCampo(63, 71, " ", $nossoNumero, $detalhe); //NOSSO NÚMERO - numeração de acordo com critérios da empresa. não pode ser zerado e que não pode ser repetido, ou seja, cada título deverá conter uma numeração única.
    $detalhe = setCampo(72, 101, " ", "", $detalhe); //deixar em branco
    $detalhe = setCampo(102, 102, " ", "0", $detalhe); //0 - codigo IOF
    $detalhe = setCampo(103, 104, " ", "00", $detalhe); //00 - Tipo de Moeda: REAL
    $detalhe = setCampo(105, 105, " ", "", $detalhe); // deixar em branco
    $detalhe = setCampo(106, 107, " ", "", $detalhe); //3ª Instrução de Cobrança (utilizar somente quando 2ª Instrução = 10) Números de dias para protesto (somente para cobrança simples)
    $detalhe = setCampo(108, 108, " ", "1", $detalhe); //Identificação Tipo de Carteira. 1 = Cobrança Simples ou 2 = Cobrança Vinculada
    $detalhe = setCampo(109, 110, " ", "01", $detalhe); //Tipo de Ocorrência - 01 - entrada de títulos
    $detalhe = setCampo(111, 120, " ", $codigoBoleto, $detalhe); //Identificação do Título na Empresa. Número adotado e controlado pela empresa para identificação do título
    $detalhe = setCampo(121, 126, " ", $dataVenvimentoTitulo, $detalhe); //data de vencimento titulo. formato DDMMAA
    $detalhe = setCampo(127, 139, "0", $valorNominalTitulo, $detalhe); //Valor do Título com 2 Decimais (sem pontos e vírgulas)
    $detalhe = setCampo(140, 142, " ", "422", $detalhe); //422 - Codigo do Banco da cobranca
    $detalhe = setCampo(143, 147, " ", $agenciaClienteSafra, $detalhe); //Agência encarregada da cobrança. Informar o mesmo código de agência utilizado na posição 18 a 22
    $detalhe = setCampo(148, 149, " ", "01", $detalhe); //Espécie do Título. Informar: 01 = Duplicata Mercantil; 02 = Nota Promissória; 03 = Nota de Seguro; 05 = Recibo; 09 = Duplicata de Serviços; 99 = Outros
    $detalhe = setCampo(150, 150, " ", "A", $detalhe); //Aceite do título. A = Aceito. N = Não Aceito
    $detalhe = setCampo(151, 156, " ", $dataEmissaoTitulo, $detalhe); //Data de Emissão do Título. formato DDMMAA
    $detalhe = setCampo(157, 158, " ", "16", $detalhe); //Primeira Instrução de Cobrança. 01 – Não receber principal, sem juros de mora; 02 – Baixar, se não pago, 15 dias após o vencimento; 03 – Baixar, se não pago, 30 dias após o vencimento; 07 – Não Protestar; 08 – Não cobrar Juros de Mora; 16 – Cobrar Multa (*) (*) Para tratamento de Multa, Juros e Protesto, Vide Nota 1
    $detalhe = setCampo(159, 160, " ", "01", $detalhe); //Segunda Instrução de Cobrança. 01 – Cobrar Juros de Mora (*); 10 – Protesto Automático (Somente para Cobrança Simples. Na Cobrança Vinculada o protesto é automático pelo Banco) (*) Para tratamento de Multa, Juros e Protesto, Vide Nota 1
    $detalhe = setCampo(161, 173, "0", $jurosDeMoraDia, $detalhe); //Juros de mora por dia em reais duas casas decimais (sem pontos e sem virgulas)
    $detalhe = setCampo(174, 179, " ", $dataLimiteDesconto, $detalhe); //Data Limte para Desconto. Utilizar o formato DDMMAA
    $detalhe = setCampo(180, 192, "0", "", $detalhe); // Valor do Desconto Concedido. Caso vá utilizar
    $detalhe = setCampo(193, 205, "0", "", $detalhe); // IOF para seguradoras. do contrário preencher com zeros
    $detalhe = setCampo(206, 211, " ", $dataMulta, $detalhe); //data a partir da qual a multa deve ser cobrada (vencimento + 1 dia no formato "ddmmaa") (vide nota 1)
    $detalhe = setCampo(211, 215, " ", $percentualMulta, $detalhe); //percentual referente à multa no formato 99v99, ex.: 2% preencher 0200 (vide nota 1)
    $detalhe = setCampo(216, 218, " ", "", $detalhe); //zeros, 000 (vide nota 1)
    $detalhe = setCampo(219, 220, " ", $tipoCpfOuCnpjPagador, $detalhe); //01 = CPF ou 02 = CNPJ do pagador
    $detalhe = setCampo(221, 234, " ", $cpfOuCnpjPagador, $detalhe); //cpf ou cnpj do pagador
    $detalhe = setCampo(235, 274, " ", $nomePagador, $detalhe); //nome completo do pagador
    $detalhe = setCampo(275, 314, " ", $enderecoPagador, $detalhe); //endereço do Pagador
    $detalhe = setCampo(315, 324, " ", $bairroPagador, $detalhe); //bairro do pagador
    $detalhe = setCampo(325, 326, " ", "", $detalhe); //deixar em branco
    $detalhe = setCampo(327, 334, " ", $cepPagador, $detalhe); //cep do pagador
    $detalhe = setCampo(335, 349, " ", $cidadePagador, $detalhe); //cidade do pagador
    $detalhe = setCampo(350, 351, " ", $estadoPagador, $detalhe); //UF do estado do pagador
    $detalhe = setCampo(352, 381, " ", "", $detalhe); //nome do sacador (somente para empresas de factoring. demais empresas deixar em branco)
    $detalhe = setCampo(382, 388, " ", "", $detalhe); //deixar em branco
    $detalhe = setCampo(389, 391, " ", "", $detalhe); //422 - banco emitente do boleto
    $detalhe = setCampo(392, 394, "0", $sequencialArquivo, $detalhe); //Número Sequencial Geração do Arquivo Remessa Sequencial começando com 001, a cada novo arquivo somar + 1(001, 002, 003 ...)
    $detalhe = setCampo(395, 400, "0", $sequencialRegistro, $detalhe); //Sequencial de registro a partir do Header (000001), no detalhe informar 000002, 000003, etc...

    $remessaTxt.="$detalhe\n";
}


/////////////////////////////////////////////
/// TRAILLER DO ARQUIVO - ÚLTIMA LINHA

$sequencialRegistro++; // Último número sequencial do registro detalhe + 1. Ex: último detalhe 000008, informar 000009

$trailler = str_repeat(" ", 400);
$trailler = setCampo(1, 1, " ", "9", $trailler); // 9 - identificacao registro trailler
$trailler = setCampo(2, 368, " ", "", $trailler); // deixar em branco
$trailler = setCampo(369, 376, "0", $quantidadeTitulos, $trailler); // Quantidade de títulos gerados no arquivo. Ex: 5 títulos = preencher 00000005
$trailler = setCampo(377, 391, "0", $valorTotalTitulos, $trailler); // Valor total dos títulos gerados no arquivo. Ex: total de R$ 350,88 = preencher 000000000035088
$trailler = setCampo(392, 394, "0", $sequencialArquivo, $trailler); // //Número Sequencial Geração do Arquivo Remessa Sequencial começando com 001, a cada novo arquivo somar + 1(001, 002, 003 ...)
$trailler = setCampo(395, 400, "0", $sequencialRegistro, $trailler); // Último número sequencial do registro detalhe + 1. Ex: último detalhe 000008, informar 000009

$remessaTxt.="$trailler\n";

echo "<textarea cols='450' rows='20'>$remessaTxt</textarea>";
