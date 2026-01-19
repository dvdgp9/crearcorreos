<?php
require 'includes/db.php';

// Use the encryption key from config.php
$encryption_key = ENCRYPTION_KEY;

$message = '';
$decrypted_password = null;

if (isset($_GET['hash'])) {
    $link_hash = $_GET['hash'];

    try {
        // Retrieve the password and IV from the database
        $stmt = $pdo->prepare("
            SELECT password, iv
            FROM passwords
            WHERE link_hash = ?
              AND created_at >= NOW() - INTERVAL 7 DAY
        ");
        $stmt->execute([$link_hash]);
        $row = $stmt->fetch();

        if ($row) {
            if (isset($_POST['confirm'])) {
                // If the user confirms, decrypt the password
                $iv = base64_decode($row['iv']); // Decode the IV from base64
                $decrypted_password = openssl_decrypt(
                    $row['password'],        // Encrypted text
                    'AES-256-CBC',          // Cipher method
                    $encryption_key,        // Encryption key
                    0,                      // Options (0 for default)
                    $iv                     // Initialization vector
                );

                // Prepare the success message
                $message = "Tu contraseña es: " . htmlspecialchars($decrypted_password);

                // Delete the password from the database
                $stmt = $pdo->prepare("DELETE FROM passwords WHERE link_hash = ?");
                $stmt->execute([$link_hash]);
            } else {
                // Show the confirmation page
                echo "<!DOCTYPE html>
                <html lang='es'>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <title>Recuperar Contraseña</title>
                    <link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap' rel='stylesheet'>
                    <link href='styles.css' rel='stylesheet'>
                    <link rel='icon' type='image/png' href='favicon.png'>
                </head>
                <body>
                    <div class='container'>
                        <img src='https://ebone.es/wp-content/uploads/2024/11/Logo-Grupo-Lineas-cuadrado-1500px.png' alt='Logo Grupo Ebone' class='logo'>
                        <h1>Recuperar Contraseña</h1>
                        <p>¿Estás segura/o de que deseas recuperar la contraseña? Este enlace solo se puede usar una vez.</p>
                        <form method='POST'>
                            <button type='submit' name='confirm' class='confirm-button'>Sí, recuperar contraseña</button>
                        </form>
                        <div class='footer'>
                            © 2025 Grupo Ebone. Todos los derechos reservados.
                        </div>
                    </div>
                </body>
                </html>";
                exit;
            }
        } else {
            $message = "El enlace no es válido o ha expirado.";
        }
    } catch (PDOException $e) {
        $message = "Ocurrió algún error al recuperar la contraseña: " . $e->getMessage();
    }
} else {
    header("Location: index.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="favicon.png">
</head>
<body>
    <div class="container">
        <img src="https://ebone.es/wp-content/uploads/2024/11/Logo-Grupo-Lineas-cuadrado-1500px.png" alt="Logo Grupo Ebone" class="logo">
        <h1>Recuperar Contraseña</h1>
        <div class="message" id="password-message"><?php echo $message; ?></div>
        <?php if (!empty($decrypted_password)): ?>
            <button class="copy-button" onclick="copyPassword()">Copiar Contraseña</button>
        <?php endif; ?>
        <div class="footer">
            © 2025 Grupo Ebone. Todos los derechos reservados.
        </div>
    </div>

    <script>
        function copyPassword() {
            const messageElement = document.getElementById('password-message');
            const passwordText = messageElement.innerText.replace("Tu contraseña es: ", "");

            navigator.clipboard.writeText(passwordText)
                .then(() => {
                    alert('Contraseña copiada al portapapeles.');
                })
                .catch(() => {
                    alert('Error al copiar la contraseña.');
                });
        }
    </script>
</body>
</html>
