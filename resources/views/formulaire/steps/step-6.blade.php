<div x-show="step === 6" x-cloak data-step="6">
    <h5 class="mb-3"><i class="bi bi-camera"></i> Autorisation de prise de vues</h5>

    <div class="card mb-4">
        <div class="card-body" style="font-size: 0.95rem;">
            <p>Nous avons l'habitude dans les ateliers thérapeutiques de proposer la prise de photos à différents temps de l'atelier. Ces photos peuvent être individuelles ou de groupe.</p>
            <p>Ces photos sont réalisées à titre de souvenir mais aussi pour vous permettre d'évaluer avec le temps tout votre cheminement thérapeutique.</p>
            <p>Nous vous proposerons, au fil des séances, de vous photographier individuellement ou en groupe avec votre cheval ou poney.</p>
            <p>Les photos vous seront remises individuellement en téléchargement informatique sécurisé à la fin de chaque séance et vous devrez <strong>vous engager au préalable à ne les utiliser que pour votre usage personnel</strong>, éventuellement à l'usage du groupe ou avec l'accord écrit des personnes photographiées en cas de diffusion.</p>
            <p>Le groupe peut également être amené à donner son accord à la diffusion de certaines photos dans le cadre de la formation des équipes encadrantes des ateliers thérapeutiques et ce, à visée didactique.</p>
            <p class="mb-0">Vous pouvez à tout moment modifier votre décision en en faisant part au responsable de l'équipe encadrante de votre atelier.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-bold">
            <em>J'inscris ci-dessous l'accord que je donne parmi les propositions qui suivent</em>
        </div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="usage_propre" class="form-check-input"
                       id="droit_usage_propre" @checked(old('droit_image') === 'usage_propre')>
                <label class="form-check-label" for="droit_usage_propre">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>pour mon usage propre</strong>
                </label>
            </div>
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="usage_confidentiel" class="form-check-input"
                       id="droit_confidentiel" @checked(old('droit_image') === 'usage_confidentiel')>
                <label class="form-check-label" for="droit_confidentiel">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>et pour un usage confidentiel au sein de l'équipe thérapeutique</strong>
                </label>
            </div>
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="diffusion" class="form-check-input"
                       id="droit_diffusion" @checked(old('droit_image') === 'diffusion')>
                <label class="form-check-label" for="droit_diffusion">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>et pour une diffusion</strong>
                </label>
            </div>
            <div class="form-check">
                <input type="radio" name="droit_image" value="refus" class="form-check-input"
                       id="droit_refus" @checked(old('droit_image') === 'refus')>
                <label class="form-check-label" for="droit_refus">
                    <strong>Je ne donne pas mon accord</strong> pour la prise de photos/vidéos
                </label>
            </div>
        </div>
    </div>
</div>
