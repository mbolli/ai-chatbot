import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

// Custom metrics
const ttfbTrend = new Trend('ttfb', true);
const errorRate = new Rate('errors');

export const options = {
  stages: [
    { duration: '10s', target: 10 },  // Ramp up to 10 users
    { duration: '30s', target: 10 },  // Stay at 10 users
    { duration: '10s', target: 50 },  // Ramp up to 50 users
    { duration: '30s', target: 50 },  // Stay at 50 users
    { duration: '10s', target: 0 },   // Ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'], // 95% of requests under 500ms
    errors: ['rate<0.01'],            // Error rate under 1%
  },
};

const BASE_URL = __ENV.BASE_URL || 'https://chat.zweiundeins.gmbh';

export default function () {
  // Test homepage
  const res = http.get(BASE_URL + '/');
  
  ttfbTrend.add(res.timings.waiting);
  
  const success = check(res, {
    'status is 200': (r) => r.status === 200,
    'response time < 500ms': (r) => r.timings.duration < 500,
    'has content': (r) => r.body && r.body.length > 0,
  });
  
  errorRate.add(!success);
  
  sleep(1);
}

export function handleSummary(data) {
  return {
    'stdout': textSummary(data, { indent: ' ', enableColors: true }),
    '/var/www/ai-chatbot/benchmarks/results/k6-summary.json': JSON.stringify(data, null, 2),
  };
}

function textSummary(data, opts) {
  const metrics = data.metrics;
  return `
=== K6 Load Test Results ===

Requests:
  Total: ${metrics.http_reqs.values.count}
  Rate: ${metrics.http_reqs.values.rate.toFixed(2)}/s

Response Time (http_req_duration):
  Avg: ${metrics.http_req_duration.values.avg.toFixed(2)}ms
  Min: ${metrics.http_req_duration.values.min.toFixed(2)}ms
  Max: ${metrics.http_req_duration.values.max.toFixed(2)}ms
  p50: ${metrics.http_req_duration.values['p(50)'].toFixed(2)}ms
  p90: ${metrics.http_req_duration.values['p(90)'].toFixed(2)}ms
  p95: ${metrics.http_req_duration.values['p(95)'].toFixed(2)}ms
  p99: ${metrics.http_req_duration.values['p(99)'].toFixed(2)}ms

TTFB (time to first byte):
  Avg: ${metrics.ttfb.values.avg.toFixed(2)}ms
  p95: ${metrics.ttfb.values['p(95)'].toFixed(2)}ms

Errors: ${(metrics.errors.values.rate * 100).toFixed(2)}%
`;
}
