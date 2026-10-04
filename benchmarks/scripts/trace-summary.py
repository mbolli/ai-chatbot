#!/usr/bin/env python3
"""Summarise a Chrome trace like the DevTools Performance panel: self time per category on the
renderer main threads, plus the first and last UpdateCounters sample (JS heap, DOM nodes, listeners).

    python3 benchmarks/scripts/trace-summary.py trace.json.gz [...]
"""
import gzip
import json
import sys
from collections import defaultdict

# Event names per DevTools category (front_end/models/trace/helpers/... EventCategory mapping)
CATEGORIES = {
    'scripting': {
        'EvaluateScript', 'v8.compile', 'v8.compileModule', 'v8.evaluateModule', 'FunctionCall', 'TimerFire',
        'EventDispatch', 'RunMicrotasks', 'FireAnimationFrame', 'FireIdleCallback', 'XHRReadyStateChange', 'XHRLoad',
        'V8.Execute', 'v8.produceCache', 'v8.produceModuleCache', 'v8.deserializeOnBackground', 'MinorGC', 'MajorGC',
        'BlinkGC.AtomicPhase', 'ThreadState::performIdleLazySweep', 'ThreadState::completeSweep', 'CpuProfiler::StartProfiling',
        'V8.GCScavenger', 'V8.GCFinalizeMC', 'V8.GCIncrementalMarking', 'V8.GCCompactor', 'V8.GC_MC_BACKGROUND_MARKING',
        'ParseHTML', 'StreamingCompileScriptParsing', 'v8.parseOnBackground', 'v8.wasm.streamFromResponseCallback',
        'ScheduleStyleInvalidationTracking', 'TimerInstall', 'TimerRemove', 'RequestAnimationFrame', 'CancelAnimationFrame',
        'WebSocketCreate', 'ResourceSendRequest', 'ResourceReceiveResponse', 'ResourceReceivedData', 'ResourceFinish',
        'RunTask',
    },
    'rendering': {
        'Layout', 'UpdateLayoutTree', 'RecalculateStyles', 'ScheduleStyleRecalculation', 'InvalidateLayout', 'HitTest',
        'PrePaint', 'Layerize', 'IntersectionObserverControllerComputeIntersections', 'ComputeIntersections',
        'LayoutInvalidationTracking', 'StyleRecalcInvalidationTracking', 'UpdateLayerTree',
    },
    'painting': {
        'Paint', 'PaintImage', 'PaintSetup', 'CompositeLayers', 'RasterTask', 'Decode Image', 'Decode LazyPixelRef',
        'GPUTask', 'UpdateLayer', 'Commit', 'ImageDecodeTask', 'Draw LazyPixelRef',
    },
}
# 'RunTask' is the generic task wrapper: its self time is 'other', not scripting
CATEGORIES['scripting'].discard('RunTask')
NAME_TO_CATEGORY = {name: cat for cat, names in CATEGORIES.items() for name in names}


def summarise(path):
    opener = gzip.open if path.endswith('.gz') else open
    with opener(path, 'rt') as f:
        data = json.load(f)
    events = data['traceEvents'] if isinstance(data, dict) else data

    main_threads = {(e['pid'], e['tid']) for e in events if e.get('ph') == 'M' and e.get('name') == 'thread_name' and e['args'].get('name') == 'CrRendererMain'}

    per_thread = defaultdict(list)
    counters = defaultdict(list)
    for e in events:
        key = (e.get('pid'), e.get('tid'))
        if key not in main_threads:
            continue
        if e.get('ph') == 'X' and 'dur' in e:
            per_thread[key].append(e)
        elif e.get('name') == 'UpdateCounters':
            counters[key].append(e)

    totals = defaultdict(float)
    for evs in per_thread.values():
        # Self time: nested complete events on one thread, children subtract from their parent
        evs.sort(key=lambda e: (e['ts'], -e['dur']))
        stack = []
        self_time = {}
        for e in evs:
            while stack and stack[-1]['ts'] + stack[-1]['dur'] <= e['ts']:
                stack.pop()
            self_time[id(e)] = e['dur']
            if stack:
                parent = stack[-1]
                self_time[id(parent)] -= min(e['dur'], parent['ts'] + parent['dur'] - e['ts'])
            stack.append(e)
        for e in evs:
            cat = NAME_TO_CATEGORY.get(e['name'], 'other')
            totals[cat] += max(self_time[id(e)], 0) / 1000

    # Counters of the busiest renderer (the page under test)
    busiest = max(counters, key=lambda k: len(counters[k]), default=None)
    first = last = {}
    if busiest:
        samples = sorted(counters[busiest], key=lambda e: e['ts'])
        first, last = samples[0]['args']['data'], samples[-1]['args']['data']
        peak_heap = max(s['args']['data'].get('jsHeapSizeUsed', 0) for s in samples)
    else:
        peak_heap = 0

    mb = lambda v: round(v / 1048576, 1) if v else None
    return {
        'trace': path,
        'scriptingMs': round(totals['scripting']),
        'renderingMs': round(totals['rendering']),
        'paintingMs': round(totals['painting']),
        'jsHeapMB': [mb(first.get('jsHeapSizeUsed')), mb(last.get('jsHeapSizeUsed'))],
        'jsHeapPeakMB': mb(peak_heap),
        'nodes': [first.get('nodes'), last.get('nodes')],
        'listeners': [first.get('jsEventListeners'), last.get('jsEventListeners')],
    }


for p in sys.argv[1:]:
    print(json.dumps(summarise(p)))
