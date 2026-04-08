<?php
/**
 * Integracao com API Asaas - PDV Pro Painel
 * Documentacao: https://docs.asaas.com
 */
class Asaas
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(PDO $pdo)
    {
        $this->apiKey = getConfig($pdo, 'asaas_api_key');
        $ambiente = getConfig($pdo, 'asaas_ambiente', 'sandbox');

        $this->baseUrl = ($ambiente === 'producao')
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';

        if (empty($this->apiKey)) {
            throw new Exception('API Key do Asaas nao configurada. Acesse Configuracoes.');
        }
    }

    private function request(string $method, string $endpoint, ?array $data = null): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'access_token: ' . $this->apiKey,
                'User-Agent: PDVPro/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'GET' && $data) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro de conexao com Asaas: $error");
        }

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = 'Erro Asaas';
            if (isset($result['errors']) && is_array($result['errors'])) {
                $msgs = [];
                foreach ($result['errors'] as $err) {
                    $msgs[] = $err['description'] ?? $err['message'] ?? json_encode($err);
                }
                $msg = implode(' | ', $msgs);
            } elseif (isset($result['message'])) {
                $msg = $result['message'];
            }
            throw new Exception($msg);
        }

        return $result;
    }

    public function findCustomer(string $cpfCnpj): ?array
    {
        $cpfCnpj = preg_replace('/\D/', '', $cpfCnpj);
        $result = $this->request('GET', '/customers', ['cpfCnpj' => $cpfCnpj]);
        return !empty($result['data']) ? $result['data'][0] : null;
    }

    public function createCustomer(array $data): array
    {
        return $this->request('POST', '/customers', [
            'name' => $data['nome'],
            'cpfCnpj' => preg_replace('/\D/', '', $data['cpf_cnpj']),
            'email' => $data['email'] ?? null,
            'phone' => preg_replace('/\D/', '', $data['telefone'] ?? ''),
            'notificationDisabled' => true,
        ]);
    }

    public function getOrCreateCustomer(array $cliente): array
    {
        $cpfCnpj = $cliente['cnpj'] ?: ($cliente['cpf'] ?? '');
        if (empty($cpfCnpj)) {
            throw new Exception('Cliente nao possui CPF/CNPJ cadastrado.');
        }

        $customer = $this->findCustomer($cpfCnpj);
        if ($customer) {
            if (empty($customer['notificationDisabled'])) {
                $this->updateCustomer($customer['id'], ['notificationDisabled' => true]);
            }
            return $customer;
        }

        return $this->createCustomer([
            'nome' => $cliente['razao_social'] ?: $cliente['nome_fantasia'],
            'cpf_cnpj' => $cpfCnpj,
            'email' => $cliente['email'] ?? null,
            'telefone' => $cliente['telefone'] ?? $cliente['whatsapp'] ?? '',
        ]);
    }

    public function updateCustomer(string $customerId, array $data): array
    {
        return $this->request('PUT', '/customers/' . $customerId, $data);
    }

    public function createPayment(array $data): array
    {
        return $this->request('POST', '/payments', [
            'customer' => $data['customer_id'],
            'billingType' => $data['billing_type'] ?? 'UNDEFINED',
            'value' => (float)$data['valor'],
            'dueDate' => $data['vencimento'],
            'description' => $data['descricao'] ?? '',
            'externalReference' => $data['referencia'] ?? null,
            'notificationDisabled' => true,
        ]);
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . $paymentId);
    }

    public function getPixQrCode(string $paymentId): ?array
    {
        return $this->request('GET', '/payments/' . $paymentId . '/pixQrCode');
    }

    public function deletePayment(string $paymentId): ?array
    {
        return $this->request('DELETE', '/payments/' . $paymentId);
    }

    // ========== Assinaturas ==========

    public function createSubscription(array $data): array
    {
        $cycle = match($data['periodo'] ?? 'mensal') {
            'trimestral' => 'QUARTERLY',
            'semestral' => 'SEMIANNUALLY',
            'anual' => 'YEARLY',
            default => 'MONTHLY',
        };

        return $this->request('POST', '/subscriptions', [
            'customer' => $data['customer_id'],
            'billingType' => $data['billing_type'] ?? 'UNDEFINED',
            'value' => (float)$data['valor'],
            'nextDueDate' => $data['vencimento'],
            'cycle' => $cycle,
            'description' => $data['descricao'] ?? '',
            'externalReference' => $data['referencia'] ?? null,
            'maxPayments' => $data['max_payments'] ?? null,
        ]);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', '/subscriptions/' . $subscriptionId);
    }

    public function cancelSubscription(string $subscriptionId): ?array
    {
        return $this->request('DELETE', '/subscriptions/' . $subscriptionId);
    }

    public function getSubscriptionPayments(string $subscriptionId): array
    {
        return $this->request('GET', '/subscriptions/' . $subscriptionId . '/payments');
    }

    public function testConnection(): array
    {
        return $this->request('GET', '/finance/balance');
    }
}
