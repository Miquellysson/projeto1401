<div class="row mb-4">
    <div class="col">
        <h3>Dashboard</h3>
        <p class="text-muted">Resumo rápido das suas operações de SMS.</p>
    </div>
</div>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Contatos</h6>
                <h3><?= $totalContacts ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Campanhas</h6>
                <h3><?= $totalCampaigns ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Mensagens enviadas</h6>
                <h3><?= $totalMessages ?></h3>
            </div>
        </div>
    </div>
</div>
<div class="mt-4">
    <a href="<?= $baseUrl ?>?route=contacts" class="btn btn-outline-primary me-2">Gerenciar contatos</a>
    <a href="<?= $baseUrl ?>?route=campaigns/new" class="btn btn-primary">Criar campanha</a>
</div>
