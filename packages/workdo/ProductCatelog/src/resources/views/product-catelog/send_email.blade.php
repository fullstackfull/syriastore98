<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ __('Product Catelog PDF') }}</title>
</head>
<style>
    table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 8px;
    }
</style>
<body>
    <h1>{{ $title }}</h1>
    <table>
        <tr>
            <th>{{__('No')}}.</th>
            <th>{{__('Image')}}</th>
            <th>{{__('Name')}}</th>
            <th>{{__('Description')}}</th>
            <th>{{__('Price')}}</th>
            <th>{{__('Link')}}</th>
        </tr>
            @foreach ($obj as $key => $product)
                <tr>
                    <td>{{ $key+1 }}</td>
                    <td>
                        <img src="{{ asset($product->cover_image_path) }}" alt="" width="100" class="cover_img{{ $product->id }}">
                    </td>
                    <td>{{ $product->name }}</td>
                    <td>{!! $product->description !!}</td>
                    <td>{{ $product->price }}</td>
                    <td>
                        <a href="{{ url($slug.'/product/'.$product->slug) }}" class="btn btn-sm">
                            {{ __('View') }}
                        </a>
                    </td>
                </tr>
            @endforeach
    </table>
</body>
</html>
