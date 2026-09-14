import './bootstrap';

// Alpine viene incluido en el bundle de Livewire, no hay que importarlo ni cargarlo aparte.

// Ejemplo: Soporte para escaneo de código de barras
document.addEventListener('DOMContentLoaded', function() {
    let barcode = '';
    let lastKeyTime = Date.now();

    document.addEventListener('keypress', function(e) {
        const currentTime = Date.now();

        // Si pasaron más de 100ms desde la última tecla, reiniciar barcode
        if (currentTime - lastKeyTime > 100) {
            barcode = '';
        }

        lastKeyTime = currentTime;

        // Si es Enter, procesar el código de barras
        if (e.key === 'Enter' && barcode.length > 0) {
            // Buscar el input de búsqueda y establecer el valor
            const searchInput = document.querySelector('input[placeholder*="Buscar"]');
            if (searchInput) {
                searchInput.value = barcode;
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            barcode = '';
        } else if (e.key !== 'Enter') {
            barcode += e.key;
        }
    });
});

// Función para formatear moneda
window.formatCurrency = function(amount) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS'
    }).format(amount);
};

// Función para formatear fecha
window.formatDate = function(date) {
    return new Intl.DateTimeFormat('es-AR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    }).format(new Date(date));
};
