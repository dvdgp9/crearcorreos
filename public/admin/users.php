<?php
/**
 * Panel de Administración de Usuarios
 */

require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$message = '';
$messageType = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $result = Auth::createUser($email, $password);
            if ($result['success']) {
                $message = 'Usuario creado correctamente';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'update':
            $id = (int) ($_POST['user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $result = Auth::updateUser($id, $email);
            if ($result['success']) {
                $message = 'Usuario actualizado correctamente';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'password':
            $id = (int) ($_POST['user_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $result = Auth::updatePassword($id, $password);
            if ($result['success']) {
                $message = 'Contraseña actualizada correctamente';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'toggle':
            $id = (int) ($_POST['user_id'] ?? 0);
            $result = Auth::toggleUserStatus($id);
            if ($result['success']) {
                $message = 'Estado de usuario cambiado';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'delete':
            $id = (int) ($_POST['user_id'] ?? 0);
            $result = Auth::deleteUser($id);
            if ($result['success']) {
                $message = 'Usuario eliminado permanentemente';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
    }
}

// Obtener lista de usuarios
$users = Auth::getAllUsers();

// Función para generar contraseña segura
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-nav {
            background: var(--primary-light);
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .admin-nav a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .admin-nav a:hover {
            text-decoration: underline;
        }
        .admin-nav a.active {
            color: var(--gray-900);
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: var(--white);
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }
        .user-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .user-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .status-active { background: var(--success); }
        .status-inactive { background: var(--error); }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1><?= e(APP_NAME) ?></h1>
            <nav>
                <span class="user-info"><?= e(Auth::getUserEmail()) ?></span>
                <a href="../dashboard.php" class="btn btn-sm btn-outline">Dashboard</a>
                <a href="../logout.php" class="btn btn-sm btn-outline">Cerrar sesión</a>
            </nav>
        </div>
    </header>
    
    <main class="main-content">
        <div class="container">
            <div class="admin-nav">
                <span>⚙️ Administración:</span>
                <a href="users.php" class="active">Usuarios</a>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2>👥 Usuarios del Sistema</h2>
                    <button type="button" class="btn btn-primary" onclick="openModal('createModal')">
                        ➕ Nuevo Usuario
                    </button>
                </div>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th>Último Login</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><strong><?= e($user['email']) ?></strong></td>
                                <td>
                                    <span class="user-status">
                                        <span class="status-dot <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                                        <?= $user['is_active'] ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                <td><?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'Nunca' ?></td>
                                <td>
                                    <div class="user-actions">
                                        <button type="button" class="btn btn-sm btn-outline" 
                                                onclick="editUser(<?= $user['id'] ?>, '<?= e($user['email']) ?>')">
                                            ✏️ Editar
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline" 
                                                onclick="changePassword(<?= $user['id'] ?>, '<?= e($user['email']) ?>')">
                                            🔑 Contraseña
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline">
                                                <?= $user['is_active'] ? '⛔ Desactivar' : '✅ Activar' ?>
                                            </button>
                                        </form>
                                        <?php if ($user['id'] !== Auth::getUserId()): ?>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('¿Eliminar permanentemente a <?= e($user['email']) ?>? Esta acción no se puede deshacer.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background: var(--error); color: white;">
                                                    🗑️ Eliminar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <!-- Modal Crear Usuario -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <button type="button" class="modal-close" onclick="closeModal('createModal')">&times;</button>
            <h2>➕ Crear Nuevo Usuario</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                
                <div class="form-group">
                    <label for="new_email">Email</label>
                    <input type="email" id="new_email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Contraseña</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="new_password" name="password" required minlength="8" 
                               value="<?= generateRandomPassword() ?>">
                        <button type="button" class="btn btn-outline" onclick="generateNewPassword()">
                            🎲 Generar
                        </button>
                    </div>
                    <small class="text-muted">Mínimo 8 caracteres</small>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Crear Usuario</button>
            </form>
        </div>
    </div>
    
    <!-- Modal Editar Usuario -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
            <h2>✏️ Editar Usuario</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Guardar Cambios</button>
            </form>
        </div>
    </div>
    
    <!-- Modal Cambiar Contraseña -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <button type="button" class="modal-close" onclick="closeModal('passwordModal')">&times;</button>
            <h2>🔑 Cambiar Contraseña</h2>
            <p id="password_user_email" class="text-muted" style="margin-bottom: 1rem;"></p>
            <form method="POST">
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="user_id" id="password_user_id">
                
                <div class="form-group">
                    <label for="change_password">Nueva Contraseña</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="change_password" name="password" required minlength="8"
                               value="<?= generateRandomPassword() ?>">
                        <button type="button" class="btn btn-outline" onclick="generateChangePassword()">
                            🎲 Generar
                        </button>
                    </div>
                    <small class="text-muted">Mínimo 8 caracteres</small>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Actualizar Contraseña</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        function editUser(id, email) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_email').value = email;
            openModal('editModal');
        }
        
        function changePassword(id, email) {
            document.getElementById('password_user_id').value = id;
            document.getElementById('password_user_email').textContent = email;
            openModal('passwordModal');
        }
        
        function generateNewPassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let pass = '';
            for (let i = 0; i < 12; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('new_password').value = pass;
        }
        
        function generateChangePassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let pass = '';
            for (let i = 0; i < 12; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('change_password').value = pass;
        }
        
        // Cerrar modal al hacer click fuera
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
