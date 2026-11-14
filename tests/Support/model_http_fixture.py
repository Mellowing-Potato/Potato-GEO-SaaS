"""Local protocol fixture. Never contacts a model provider or an external host."""
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        with open(sys.argv[1], 'a', encoding='utf-8') as log:
            log.write(json.dumps({'path': self.path, 'body': payload, 'headers': dict(self.headers)}) + '\n')
        mode = self.path.split('/')[1]
        if mode in ('stream', 'stream-timeout'):
            self.send_response(200)
            self.send_header('Content-Type', 'text/event-stream')
            self.end_headers()
            try:
                self.wfile.write(b'data: {"type":"response.created","response":{"id":"test-response"}}\n\n')
                self.wfile.flush()
                if mode == 'stream-timeout':
                    time.sleep(3)
                self.wfile.write(b'data: {"type":"response.completed","response":{"id":"test-response","status":"completed","output":[]}}\n\n')
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                pass
            return
        if mode == 'timeout':
            time.sleep(3)
        status = 500 if mode == 'error' else 200
        if self.path.endswith('/embeddings'):
            dimensions = 1536 if mode == 'wrong-dimension' else 1024
            body = {'id': 'test-same-id', 'data': [
                {'index': i, 'embedding': [0.25] * dimensions} for i, _ in enumerate(payload['input'])
            ], 'usage': {'total_tokens': 5}}
        else:
            body = {'id': 'test-same-id', 'choices': [{'message': {'content': '{"ok":true}'}, 'finish_reason': 'stop'}],
                    'usage': {'prompt_tokens': 4, 'completion_tokens': 3, 'total_tokens': 7}}
        if mode == 'no-usage':
            body.pop('usage', None)
        if mode == 'invalid-json':
            body['choices'][0]['message']['content'] = 'not JSON'
        if status == 500:
            body = {'error': 'test failure'}
        encoded = json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        try:
            self.wfile.write(encoded)
        except (BrokenPipeError, ConnectionResetError):
            pass


server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
print(server.server_address[1], flush=True)
server.serve_forever()
