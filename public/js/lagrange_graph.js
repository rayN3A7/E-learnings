const ctx = document.getElementById('graphCanvas').getContext('2d');
let chart;
let step = 0;

// Initial points from the PDF: (-1, 2), (0, 1), (1, -1)
let points = [
    { x: -1, y: 2 },
    { x: 0, y: 1 },
    { x: 1, y: -1 }
];

// Compute Lagrange basis polynomial L_i(x)
function lagrangeBasis(i, x) {
    let result = 1;
    for (let j = 0; j < points.length; j++) {
        if (j !== i) {
            result *= (x - points[j].x) / (points[i].x - points[j].x);
        }
    }
    return result;
}

// Compute the Lagrange interpolation polynomial P(x)
function lagrangePolynomial(x) {
    let result = 0;
    for (let i = 0; i < points.length; i++) {
        result += points[i].y * lagrangeBasis(i, x);
    }
    return result;
}

// Generate data for a polynomial over x range
function generatePolynomialData(func, label, color, start = -2, end = 2, stepSize = 0.1) {
    const data = [];
    for (let x = start; x <= end; x += stepSize) {
        data.push({ x: x, y: func(x) });
    }
    return { label, data, borderColor: color, backgroundColor: 'transparent', pointRadius: 0, borderWidth: 2 };
}

// Initialize Chart.js
function initChart() {
    chart = new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [
                {
                    label: 'Data Points',
                    data: points,
                    borderColor: 'red',
                    backgroundColor: 'red',
                    pointRadius: 6,
                    pointStyle: 'circle',
                    showLine: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { type: 'linear', position: 'bottom', min: -2, max: 2, title: { display: true, text: 'x' } },
                y: { min: -3, max: 3, title: { display: true, text: 'y' } }
            },
            plugins: {
                tooltip: {
                    enabled: false,
                    external: function(context) {
                        const tooltipEl = document.getElementById('chartjs-tooltip') || document.createElement('div');
                        tooltipEl.id = 'chartjs-tooltip';
                        document.body.appendChild(tooltipEl);
                        if (!context.tooltip.opacity) {
                            tooltipEl.style.opacity = 0;
                            return;
                        }
                        tooltipEl.style.opacity = 1;
                        tooltipEl.style.position = 'absolute';
                        tooltipEl.style.background = 'rgba(0, 0, 0, 0.8)';
                        tooltipEl.style.color = 'white';
                        tooltipEl.style.padding = '5px';
                        tooltipEl.style.borderRadius = '3px';
                        const dataset = context.tooltip.dataPoints[0].dataset;
                        const index = context.tooltip.dataPoints[0].dataIndex;
                        if (dataset.label === 'Data Points') {
                            tooltipEl.innerHTML = `Point: (${points[index].x}, ${points[index].y})`;
                        } else {
                            tooltipEl.innerHTML = `${dataset.label}: y = ${context.tooltip.dataPoints[0].parsed.y.toFixed(2)} at x = ${context.tooltip.dataPoints[0].parsed.x.toFixed(2)}`;
                        }
                        tooltipEl.style.left = context.tooltip.x + 'px';
                        tooltipEl.style.top = context.tooltip.y + 'px';
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeInOutQuad'
                }
            }
        }
    });
}

// Update chart based on current step
function updateChart() {
    const datasets = [
        {
            label: 'Data Points',
            data: points,
            borderColor: 'red',
            backgroundColor: 'red',
            pointRadius: 6,
            pointStyle: 'circle',
            showLine: false
        }
    ];

    if (step >= 1) {
        datasets.push(generatePolynomialData(
            x => lagrangeBasis(0, x),
            'L₀(x) = x(x-1)/2',
            'blue'
        ));
    }
    if (step >= 2) {
        datasets.push(generatePolynomialData(
            x => lagrangeBasis(1, x),
            'L₁(x) = -(x+1)(x-1)',
            'green'
        ));
    }
    if (step >= 3) {
        datasets.push(generatePolynomialData(
            x => lagrangeBasis(2, x),
            'L₂(x) = (x+1)x/2',
            'purple'
        ));
    }
    if (step >= 4) {
        datasets.push(generatePolynomialData(
            lagrangePolynomial,
            'P₂(x) = -0.5x² - 1.5x + 1',
            'orange'
        ));
    }

    chart.data.datasets = datasets;
    chart.update();

    // Update guide text
    const guideText = document.getElementById('guideText');
    switch (step) {
        case 0:
            guideText.innerText = 'Step 0: Observe the given points (-1, 2), (0, 1), (1, -1). Move the slider to see the Lagrange basis polynomial L₀(x).';
            break;
        case 1:
            guideText.innerText = 'Step 1: L₀(x) = x(x-1)/2 is the basis polynomial for point (-1, 2). It equals 1 at x = -1 and 0 at x = 0, 1. Move the slider to see L₁(x).';
            break;
        case 2:
            guideText.innerText = 'Step 2: L₁(x) = -(x+1)(x-1) is the basis polynomial for point (0, 1). It equals 1 at x = 0 and 0 at x = -1, 1. Move the slider to see L₂(x).';
            break;
        case 3:
            guideText.innerText = 'Step 3: L₂(x) = (x+1)x/2 is the basis polynomial for point (1, -1). It equals 1 at x = 1 and 0 at x = -1, 0. Move the slider to see P₂(x).';
            break;
        case 4:
            guideText.innerText = 'Step 4: P₂(x) = -0.5x² - 1.5x + 1 is the Lagrange polynomial interpolating all points. Try evaluating at x = 0.5!';
            break;
    }
}

// Evaluate polynomial at a given x
window.evaluatePolynomial = function() {
    const x = parseFloat(document.getElementById('evalX').value);
    if (isNaN(x)) {
        alert('Please enter a valid number for x.');
        return;
    }
    const y = lagrangePolynomial(x);
    alert(`P₂(${x}) = ${y.toFixed(3)}`);
    if (step < 4) {
        document.getElementById('stepSlider').value = 4;
        step = 4;
        document.getElementById('stepValue').innerText = step;
        updateChart();
    }
};

// Reset graph
window.resetGraph = function() {
    step = 0;
    document.getElementById('stepSlider').value = step;
    document.getElementById('stepValue').innerText = step;
    document.getElementById('evalX').value = 0.5;
    points = [
        { x: -1, y: 2 },
        { x: 0, y: 1 },
        { x: 1, y: -1 }
    ];
    updateChart();
};

// Update step slider
document.getElementById('stepSlider').addEventListener('input', function() {
    step = parseInt(this.value);
    document.getElementById('stepValue').innerText = step;
    updateChart();
});

// Initialize chart on load
initChart();