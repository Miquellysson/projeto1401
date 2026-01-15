<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center page-header mb-3">
    <div>
        <h4>Histórico de Campanhas</h4>
        <p class="text-muted mb-0">Veja resumo e status das campanhas enviadas.</p>
    </div>
    <a href="<?= $baseUrl ?>?route=campaigns/new" class="btn btn-primary">Nova Campanha</a>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>Nome</th>
                    <th>Criada em</th>
                    <th>Total</th>
                    <th>Enviadas</th>
                    <th>Falhas</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                    <tr>
                        <td><?= htmlspecialchars($campaign['name']) ?></td>
                        <td><?= htmlspecialchars($campaign['created_at']) ?></td>
                        <td><?= $campaign['total'] ?? 0 ?></td>
                        <td><?= $campaign['sent'] ?? 0 ?></td>
                        <td><?= $campaign['failed'] ?? 0 ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= $baseUrl ?>?route=campaigns/show&id=<?= $campaign['id'] ?>">Detalhes</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($campaigns)): ?>
                    <tr><td colspan="6" class="text-center text-muted">Nenhuma campanha enviada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl ?>?route=campaigns&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
