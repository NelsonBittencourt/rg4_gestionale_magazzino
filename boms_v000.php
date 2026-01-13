<?php
/*
 * @Author: Nelson Rossi Bittencourt (nbittencourt@hotmail.com)
 * @Date: 2026-01-11 17:28:47 
 * @Last Modified by: Nelson Rossi Bittencourt
 * @Last Modified time: 2026-01-12 14:00:01 
 * @Description: Distinta Materiali/Bill of Materials (BOM) for RG4 Gestionale Magazzino Project 
 * @Version: 0.0.0
 
 TODO:
	-Migliorare l'aspetto del report;
	-Includere una ricerca di codici componente equivalenti o simili;
	-Includere opzioni per gestire vari tipi di file BOM;
	-Aggiungere un collegamento al header file;
	-Etc ... 
*/

require_once '../includes/db_connect.php';
require_once '../includes/auth_check.php';
ini_set('display_errors', 0);

// Messaggi da sessione
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Intestazione
include '../includes/header.php';

// POST con il nome file BOM 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bomFile1']))
{	
	$bomFileName = $_FILES['bomFile1'];	
}

?>

<!-- Image container -->
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fa-solid fa-list me-2"></i>BOMS</h2>
</div>


<!-- BOM file selection form  -->
<form method="POST" enctype="multipart/form-data">
	<div class="mb-3">
	<label for="bomFile" class="form-label">BOM file:</label>
		<input type="file" class="form-control" id="bomFile2" name="bomFile1" accept=".csv" required>		
		<br>
		<button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload me-1"></i>Analisa</button>
	</div>
</form>
  
<?php
// È stato fornito un nome per BOM file?
if ($bomFileName['name']!=NULL)
{	
	// Crea una tabella temporanea in database
	$sqlCreateTemp = "CREATE TEMPORARY TABLE bom (bom_codice_prodotto VARCHAR(50),bom_quantity INT)";
	$pdo->exec($sqlCreateTemp);

	// Popolare la tabella temporanea con i dati del BOM file
	$arquivo = $bomFileName['name'];
	if (($handle = fopen($arquivo, "r")) !== FALSE) 
	{
		$pdo->beginTransaction();    
		$stmtInsert = $pdo->prepare("INSERT INTO bom (bom_codice_prodotto, bom_quantity) VALUES (?, ?)");

		// Salta la prima riga (intestazione) del BOM file
		fgetcsv($handle,1000,";");
	
		// Esegue l'iterazione delle righe del BOM file BOM per popolare la tabella temporanea
		while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) 
		{	
			// Kicad BOM file:
			// codice   -> colonna 1;
			// quantità -> colonna 3;
			// per impostazione predefinita, Kicad usa "'" per ogni string.
			$codice = trim(str_replace("'",'',$data[1]));
			$tmp_quant = trim(str_replace("'",'',$data[3]));
			$quantita = isset($tmp_quant) ? (int)$tmp_quant : 1;         
			$stmtInsert->execute([$codice, $quantita]);
		}
    
		$pdo->commit(); 
		fclose($handle);
	} 
	else 
	{
		die("Errore durante la lettura del file BOM.");
	}

	// Esegue SQL utilizzando la tabella temporanea e la tabella dei componenti
	$sqlAnalise = "
		SELECT 
			t.bom_codice_prodotto,
			t.bom_quantity,
			e.codice_prodotto,
			COALESCE(e.quantity_min, 0) AS scorte_min,
			COALESCE(e.quantity, 0) AS scorte_corrente,
			CASE 
				WHEN e.codice_prodotto IS NULL THEN 'Prodotto non registrato'
				WHEN e.quantity < t.bom_quantity THEN 'Scorte insufficienti'
				ELSE 'OK'
			END AS azione
		FROM 
			bom t 
		LEFT JOIN 
			components e ON e.codice_prodotto LIKE CONCAT(t.bom_codice_prodotto, '%')
		ORDER BY 
			azione DESC";

	$stmt = $pdo->query($sqlAnalise);
	$resultados = $stmt->fetchAll();

	// Crea un semplice report
	echo "<h2>BOM Report</h2>";
	echo "<table border='1' cellpadding='5'>";
	echo "<tr><th>Codice</th><th>Quantità desiderata</th><th>
	Quantità in magazzino</th><th>Status</th><th>A comprare</th></tr>";

	foreach ($resultados as $row) 
	{
		$style = ($row['azione'] != 'OK') ? 'style="background-color: #0fccff;"' : '';
    
		echo "<tr $style>";
		echo "<td>{$row['bom_codice_prodotto']}</td>";
		echo "<td>{$row['bom_quantity']}</td>";    
		echo "<td>{$row['scorte_corrente']}</td>";
		echo "<td><strong>{$row['azione']}</strong></td>";
	
		$acquistare = $row['scorte_min'] - ($row['scorte_corrente']-$row['bom_quantity']);
		if ($acquistare > 0)
		{
			echo "<td><strong>Acquistare (BOM + scorte minimo) : {$acquistare}</strong></td>";
		}
	
	
		if ($row['azione']!='Prodotto non registrato' && $row['codice_prodotto']!=$row['bom_codice_prodotto'])
		{
			echo "<td><strong>Codice Parcialmente encontrado ({$row['codice_prodotto']})</strong></td>";
		}	
	
		echo "</tr>";
	}
	echo "</table>";
	unset($_FILES);
	$bomFileName=NULL;
}
?>


<?php 
// Piè di pagina
include '../includes/footer.php'; 
?>