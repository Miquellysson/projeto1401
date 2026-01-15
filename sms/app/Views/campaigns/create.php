<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center page-header mb-3">
    <div>
        <h4>Nova Campanha</h4>
        <p class="text-muted mb-0">Envie SMS para todos os contatos ou selecione destinatários específicos.</p>
    </div>
    <a href="<?= $baseUrl ?>?route=campaigns" class="btn btn-outline-secondary">Histórico</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" action="<?= $baseUrl ?>?route=campaigns/store">
            <div class="mb-3">
                <label class="form-label">Nome da campanha</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Mensagem SMS</label>
                <textarea name="message" rows="3" class="form-control" maxlength="320" required></textarea>
                <div class="form-text">Limite típico de 160-320 caracteres dependendo do provedor.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Destinatários</label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="send_to" id="sendAll" value="all" checked>
                    <label class="form-check-label" for="sendAll">Todos os contatos</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="send_to" id="sendSelected" value="selected">
                    <label class="form-check-label" for="sendSelected">Selecionar contatos</label>
                </div>
            </div>
            <div id="contactsList" class="scrollable-list mb-3 d-none">
                <?php foreach ($contacts as $contact): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="contacts[]" value="<?= $contact['id'] ?>" id="c<?= $contact['id'] ?>">
                        <label class="form-check-label" for="c<?= $contact['id'] ?>">
                            <?= htmlspecialchars($contact['name']) ?> (<?= htmlspecialchars($contact['phone']) ?>)
                        </label>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($contacts)): ?>
                    <p class="text-muted mb-0">Nenhum contato cadastrado.</p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">Enviar campanha</button>
        </form>
    </div>
</div>

<script>
    const sendSelected = document.getElementById('sendSelected');
    const sendAll = document.getElementById('sendAll');
    const list = document.getElementById('contactsList');
    function toggleList() {
        list.classList.toggle('d-none', !sendSelected.checked);
    }
    toggleList();
    sendSelected.addEventListener('change', toggleList);
    sendAll.addEventListener('change', toggleList);
</script>
