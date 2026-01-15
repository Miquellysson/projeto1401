<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center page-header mb-3">
    <div>
        <h4>Campanha: <?= htmlspecialchars($campaign['name']) ?></h4>
        <p class="text-muted mb-0">Criada em <?= htmlspecialchars($campaign['created_at']) ?></p>
    </div>
    <a href="<?= $baseUrl ?>?route=campaigns" class="btn btn-outline-secondary">Voltar</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4 col-lg-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Total</h6>
                <h4><?= $campaign['total'] ?? 0 ?></h4>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4 col-lg-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Enviadas</h6>
                <h4><?= $campaign['sent'] ?? 0 ?></h4>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4 col-lg-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Falhas</h6>
                <h4><?= $campaign['failed'] ?? 0 ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h6 class="mb-3">Destinatários</h6>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>Contato</th>
                    <th>Telefone</th>
                    <th>Status</th>
                    <th>Mensagem de erro</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recipients as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['contact_name']) ?></td>
                        <td><?= htmlspecialchars($item['contact_phone']) ?></td>
                        <td>
                            <?php if ($item['status'] === 'sent'): ?>
                                <span class="badge bg-success">Enviado</span>
                            <?php elseif ($item['status'] === 'failed'): ?>
                                <span class="badge bg-danger">Falhou</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($item['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($item['error_message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recipients)): ?>
                    <tr><td colspan="4" class="text-center text-muted">Sem destinatários.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
