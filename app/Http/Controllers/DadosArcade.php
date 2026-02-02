<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Firebase\JWT\JWT;

class DadosArcade extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'usuario'=>'required|string|max:20',
            'pontos'=>'required|integer',
        ]);

        // 1. Carregamento das credenciais
        $firebaseConfig=env('FIREBASE_CREDENTIALS');
        if($firebaseConfig){
            $serviceAccount = json_decode($firebaseConfig, true);
        }else{
            $path=storage_path('app/firebase.json');
            if(!file_exists($path)){
                return response()->json(['error'=>'Credenciais do Firebase não encontradas.'], 500);
            }
            $serviceAccount=json_decode(file_get_contents($path), true);
        }

        $accessToken = $this->gerarToken($serviceAccount);
        $projectId   = $serviceAccount['project_id'];
        $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/Ordem/{$request->usuario}";
        
        // Busca os dados atuais do usuário no Firestore
        /** @var \Illuminate\Http\Client\Response $currentDoc */
        $currentDoc = Http::withToken($accessToken)->get($url);

        if ($currentDoc->successful()) {
            $data = $currentDoc->json();
            // Pega o valor existente. Se não achar o campo 'pontos', assume 0.
            $pontosAtuais = $data['fields']['pontos']['integerValue'] ?? 0;

            if ($request->pontos <= (int)$pontosAtuais) {
                return response()->json([
                    'status' => 'Pontuação menor que pontuação atual',
                    'mensagem' => 'A pontuação atual (' . $pontosAtuais . ') é maior ou igual à enviada.'
                ]);
            }
        }

        // Se chegou aqui, é porque a pontuação é maior ou o usuário é novo
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($accessToken)->patch($url, [
            "fields" => [
                "usuario" => ["stringValue" => $request->usuario],
                "pontos"  => ["integerValue" => (string)$request->pontos],
            ]
        ]);

        return response()->json([
            'status'            => 'Recorde atualizado!',
            'firebase_response' => $response->json(),
        ]);
    }

    private function gerarToken(array $serviceAccount): string
    {
        $now = time();
        $payload = [
            "iss"   => $serviceAccount['client_email'],
            "scope" => "https://www.googleapis.com/auth/datastore",
            "aud"   => "https://oauth2.googleapis.com/token",
            "exp"   => $now + 3600,
            "iat"   => $now,
        ];

        $jwt = JWT::encode($payload, $serviceAccount['private_key'], 'RS256');

        /** @var \Illuminate\Http\Client\Response $tokenResponse */
        $tokenResponse = Http::asForm()->post("https://oauth2.googleapis.com/token", [
            "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion"  => $jwt,
        ]);

        return $tokenResponse->throw()->json()['access_token'];
    }
}