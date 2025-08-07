const ctx = document.getElementById('graphCanvas').getContext('2d');
let chart;
let iteration = 0;
const maxIterations = 10;
let initialGuess = 1;

// Function to evaluate f(x) = x^3 - x - 2 (example for root-finding)
function f(x) {
    return x * x * x - x - 2;
}

// Derivative of f(x)
function df(x) {
    return 3 * x * x - 1;
}

// Generate data for the function curve
function generateFunctionData() {
    const data = [];
    for (let x = -3; x <= 3; x += 0.1) {
        data.push({ x: x, y: f(x) });
    }
    return data;
}

// Perform Newton's method iteration
function newtonIteration(x0) {
    return x0 - f(x0) / df(x0);
}

// Initialize Chart.js
function initChart() {
    chart = new Chart(ctx, {
        type: 'line',
        data: {
            datasets: [
                {
                    label: 'f(x) = x³ - x - 2',
                    data: generateFunctionData(),
                    borderColor: 'blue',
                    backgroundColor: 'rgba(0, 0, 255, 0.1)',
                    fill: false,
                    pointRadius: 0
                },
                {
                    label: 'Current Point',
                    data: [{ x: initialGuess, y: f(initialGuess) }],
                    borderColor: 'red',
                    backgroundColor: 'red',
                    pointRadius: 6,
                    pointStyle: 'circle',
                    showLine: false
                },
                {
                    label: 'Tangent Line',
                    data: [],
                    borderColor: 'green',
                    borderDash: [5, 5],
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { type: 'linear', position: 'bottom', min: -3, max: 3 },
                y: { min: -10, max: 10 }
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
                        tooltipEl.innerHTML = `x: ${context.tooltip.dataPoints[0].parsed.x.toFixed(2)}, y: ${context.tooltip.dataPoints[0].parsed.y.toFixed(2)}`;
                        tooltipEl.style.left = context.tooltip.x + 'px';
                        tooltipEl.style.top = context.tooltip.y + 'px';
                    }
                }
            }
        }
    });
}

// Update chart with new iteration
function updateChart() {
    let x = initialGuess;
    for (let i = 0; i < iteration; i++) {
        x = newtonIteration(x);
    }
    const y = f(x);
    const slope = df(x);
    const tangentLine = [
        { x: x - 1, y: y - slope },
        { x: x + 1, y: y + slope }
    ];

    chart.data.datasets[1].data = [{ x: x, y: y }];
    chart.data.datasets[2].data = tangentLine;
    chart.update();

    // Update guide text
    const guideText = document.getElementById('guideText');
    if (iteration === 0) {
        guideText.innerText = "Start by adjusting the initial guess for Newton's method. Click 'Run Iteration' to see the next step.";
    } else {
        guideText.innerText = `Iteration ${iteration}: The point moves to x = ${x.toFixed(2)}. The tangent line shows the slope at this point. Click 'Run Iteration' to continue.`;
    }
}

// Run single iteration
window.runIteration = function() {
    if (iteration < maxIterations) {
        iteration++;
        document.getElementById('iterationValue').innerText = iteration;
        updateChart();
    }
};

// Reset graph
window.resetGraph = function() {
    iteration = 0;
    initialGuess = parseFloat(document.getElementById('initialGuess').value);
    document.getElementById('iterationValue').innerText = iteration;
    updateChart();
};

// Update initial guess
document.getElementById('initialGuess').addEventListener('input', function() {
    initialGuess = parseFloat(this.value);
    document.getElementById('initialGuessValue').innerText = initialGuess.toFixed(1);
    resetGraph();
});

// Update iteration slider
document.getElementById('iterationSlider').addEventListener('input', function() {
    iteration = parseInt(this.value);
    document.getElementById('iterationValue').innerText = iteration;
    updateChart();
});

// Initialize chart on load
initChart();