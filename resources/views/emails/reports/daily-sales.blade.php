@component('mail::message')
    # Günlük Satış Raporu - {{ \Carbon\Carbon::parse($data['date'])->format('d.m.Y') }}

    Merhaba,

    {{ \Carbon\Carbon::parse($data['date'])->format('d.m.Y') }} tarihli günlük satış raporunuz aşağıdadır.

    ## Genel Özet

    @component('mail::panel')
        - **Toplam Sipariş:** {{ $data['total_orders'] }}
        - **Toplam Gelir:** {{ number_format($data['total_revenue'], 2, ',', '.') }} ₺
        - **Ortalama Sipariş Değeri:** {{ number_format($data['average_order_value'], 2, ',', '.') }} ₺
        - **Yeni Müşteri:** {{ $data['new_customers'] }}
    @endcomponent

    ## Sipariş Durumları

    @component('mail::table')
        | Durum | Adet |
        | :---- | ---: |
        @foreach($data['orders_by_status'] as $status => $count)
            | {{ ucfirst($status) }} | {{ $count }} |
        @endforeach
    @endcomponent

    ## En Çok Satan Ürünler

    @component('mail::table')
        | Ürün | SKU | Adet | Gelir |
        | :--- | :-- | ---: | ----: |
        @foreach($data['top_products'] as $product)
            | {{ Str::limit($product->name, 30) }} | {{ $product->sku }} | {{ $product->total_quantity }} | {{ number_format($product->total_revenue, 2, ',', '.') }} ₺ |
        @endforeach
    @endcomponent

    ## Ödeme Yöntemleri

    @component('mail::table')
        | Yöntem | Adet | Tutar |
        | :----- | ---: | ----: |
        @foreach($data['payment_breakdown'] as $payment)
            | {{ $payment->payment_method == 'credit_card' ? 'Kredi Kartı' : ($payment->payment_method == 'bank_transfer' ? 'Havale/EFT' : 'Cari Bakiye') }} | {{ $payment->count }} | {{ number_format($payment->total, 2, ',', '.') }} ₺ |
        @endforeach
    @endcomponent

    @component('mail::button', ['url' => config('app.url') . '/admin/dashboard'])
        Detaylı Raporu Görüntüle
    @endcomponent

    Bu otomatik bir rapordur. Detaylı analizler için admin panelinizi ziyaret edebilirsiniz.

    Saygılarımızla,<br>
    {{ config('app.name') }}
@endcomponent
