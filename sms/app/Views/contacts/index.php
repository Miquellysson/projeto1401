<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center page-header mb-3">
    <div>
        <h4>Contatos</h4>
        <p class="text-muted mb-0">Cadastre contatos manualmente ou importe via CSV.</p>
    </div>
    <a href="<?= $baseUrl ?>?route=dashboard" class="btn btn-outline-secondary">Voltar</a>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-md-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Lista de contatos</h6>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Telefone</th>
                            <th>Criado em</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?= htmlspecialchars($contact['name']) ?></td>
                                <td><?= htmlspecialchars($contact['phone']) ?></td>
                                <td><?= htmlspecialchars($contact['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contacts)): ?>
                            <tr><td colspan="3" class="text-center text-muted">Nenhum contato cadastrado.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $baseUrl ?>?route=contacts&page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-3">Adicionar contato</h6>
                <form method="post" action="<?= $baseUrl ?>?route=contacts/store">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Telefone (formato internacional)</label>
                        <input type="text" name="phone" class="form-control" placeholder="+5511999999999" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Importar CSV</h6>
                <p class="text-muted small mb-2">Formato esperado: nome;telefone</p>
                <form method="post" action="<?= $baseUrl ?>?route=contacts/import" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" name="csv" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Importar</button>
                </form>
            </div>
        </div>
    </div>
</div>
