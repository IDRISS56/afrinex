</main>
<!-- Footer -->
<footer class="bg-afrinex-dark text-white-60">
    <div class="container-custom py-5">
        <div class="row g-5">
            <div class="col-md-6 col-lg-3">
                <div class="fs-3 font-display fw-bold text-white mb-2"><span class="text-afrinex-gold">A</span>FRINEX <span class="text-white-40 text-uppercase tracking-wider" style="font-size:0.875rem;">Research</span></div>
                <p class="font-body" style="font-size:0.875rem;line-height:1.625;">Cabinet d'études, de recherche et d'intelligence de marché. L'intelligence stratégique pour le marché africain depuis 2015.</p>
                <div class="d-flex flex-column gap-2" style="font-size:0.875rem;">
                    <div class="d-flex align-items-center gap-3"><i class="fas fa-phone text-afrinex-gold" style="width:16px;"></i><span>+225 27 XX XX XX XX</span></div>
                    <div class="d-flex align-items-center gap-3"><i class="fas fa-envelope text-afrinex-gold" style="width:16px;"></i><span><a href="mailto:contact@afrinex.com" class="text-decoration-none text-white-60">contact@afrinex.com</a></span></div>
                    <div class="d-flex align-items-center gap-3"><i class="fas fa-map-marker-alt text-afrinex-gold" style="width:16px;"></i><span>Abidjan, Côte d'Ivoire</span></div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <h4 class="text-white font-display fw-semibold mb-3">Services</h4>
                <ul class="list-unstyled d-flex flex-column gap-2" style="font-size:0.875rem;">
                    <li><a href="#" class="footer-link text-decoration-none">Data Collection</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Études Quantitatives</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Études Qualitatives</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Études Sectorielles</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">RH & Climat Social</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Intelligence Économique</a></li>
                </ul>
            </div>
            <div class="col-md-6 col-lg-3">
                <h4 class="text-white font-display fw-semibold mb-3">Solutions</h4>
                <ul class="list-unstyled d-flex flex-column gap-2" style="font-size:0.875rem;">
                    <li><a href="#" class="footer-link text-decoration-none">Business Intelligence</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Machine Learning</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Consumer Insights</a></li>
                    <li><a href="#" class="footer-link text-decoration-none">Data Visualization</a></li>
                </ul>
            </div>
            <div class="col-md-6 col-lg-3">
                <h4 class="text-white font-display fw-semibold mb-3">Certifications</h4>
                <div class="d-flex flex-column gap-2">
                    <div class="bg-white-5 rounded-3 p-3 text-center"><div class="text-afrinex-gold font-display fw-bold fs-5">ESOMAR</div><div style="font-size:0.75rem;">Member</div></div>
                    <div class="bg-white-5 rounded-3 p-3 text-center"><div class="text-afrinex-cyan font-display fw-bold fs-5">ISO 20252</div><div style="font-size:0.75rem;">Certified</div></div>
                    <div class="bg-white-5 rounded-3 p-3 text-center"><div class="text-white font-display fw-bold fs-5">RGPD</div><div style="font-size:0.75rem;">Compliant</div></div>
                </div>
            </div>
        </div>
    </div>
    <div class="border-top border-white-10">
        <div class="container-custom py-3">
            <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">
                <p class="text-white-40" style="font-size:0.875rem;">&copy; <?= date('Y') ?> AFRINEX Research. Tous droits réservés.</p>
                <div class="d-flex gap-4" style="font-size:0.75rem;">
                    <a href="#" class="text-decoration-none text-white-60 hover-text-white transition-colors">Mentions légales</a>
                    <a href="#" class="text-decoration-none text-white-60 hover-text-white transition-colors">Politique de confidentialité</a>
                    <a href="#" class="text-decoration-none text-white-60 hover-text-white transition-colors">CGU</a>
                    <a href="#" class="text-decoration-none text-white-60 hover-text-white transition-colors">Cookies</a>
                </div>
            </div>
        </div>
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Navbar scroll
    const navbar = document.getElementById('navbar');
    const navLogo = document.getElementById('nav-logo');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
            navLogo.classList.remove('text-white');
            navLogo.classList.add('text-afrinex-navy');
        } else {
            navbar.classList.remove('scrolled');
            navLogo.classList.add('text-white');
            navLogo.classList.remove('text-afrinex-navy');
        }
    });
    // Mobile menu
    document.getElementById('mobile-menu-btn').addEventListener('click', function() {
        var menu = document.getElementById('mobile-menu');
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    });
    // Animations des compteurs
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const target = parseInt(el.dataset.count);
                const suffix = el.dataset.suffix || '';
                let current = 0;
                const inc = target / 60;
                const timer = setInterval(() => {
                    current += inc;
                    if (current >= target) { current = target; clearInterval(timer); }
                    el.textContent = Math.floor(current).toLocaleString() + suffix;
                }, 25);
                counterObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });
    document.querySelectorAll('[data-count]').forEach(el => counterObserver.observe(el));
</script>
</body>
</html>